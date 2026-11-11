<?php

namespace App\Models;

use App\Support\LessonContent;
use App\Enums\ContentStatus;
use App\Models\Concerns\CleansUpFiles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Support\MediaStorage;

/**
 * Bài học trong thư viện dùng chung.
 * Nội dung là JSON của Editor.js: bản nháp (content_draft) và bản đã xuất bản (content_published).
 * Học viên chỉ thấy bản đã xuất bản.
 */
class Lesson extends Model
{
    use CleansUpFiles, SoftDeletes;

    /** Icon: xoá file khi bỏ / thay icon, hoặc khi xoá vĩnh viễn. */
    protected array $fileAttributes = ['icon'];

    public const KIND_LESSON = 'lesson';

    /** Coaching 1-1 với giáo viên: không có nội dung, không vào thư viện, không tính % / không chặn bài sau. */
    public const KIND_COACHING = 'coaching';

    public const COACHING_DEFAULT_TITLE = 'Trao đổi trực tiếp';

    protected $attributes = ['kind' => self::KIND_LESSON];

    protected $fillable = [
        'kind',
        'owner_id',
        'title',
        'icon',
        'summary',
        'content_draft',
        'content_published',
        'has_draft_content',
        'content_updated_at',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'content_draft' => 'array',
            'content_published' => 'array',
            'has_draft_content' => 'boolean',
            'content_updated_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Lesson $lesson) {
            $lesson->owner_id ??= auth()->id();
        });

        static::saving(function (Lesson $lesson) {
            // Đánh dấu thời điểm sửa bản nháp → biết có thay đổi chưa xuất bản
            if ($lesson->isDirty('content_draft')) {
                $lesson->content_updated_at = now();
                $lesson->has_draft_content = count($lesson->content_draft['blocks'] ?? []) > 0;
            }
        });

        // Xóa bài khỏi thư viện → gỡ khỏi mọi lộ trình
        // Xoá vĩnh viễn → xoá video bài học (file trên ổ tài liệu)
        static::forceDeleted(function (Lesson $lesson) {
            $lesson->videos()->get()->each->delete();
        });

        static::deleted(function (Lesson $lesson) {
            $lesson->pathLessons()->delete();
        });

        // Coaching chỉ sống trong lộ trình: gỡ khỏi lộ trình → xoá luôn
        static::creating(function (Lesson $lesson) {
            if ($lesson->kind === self::KIND_COACHING) {
                $lesson->title = filled($lesson->title) ? $lesson->title : self::COACHING_DEFAULT_TITLE;
            }
        });
    }

    // ── Nội dung ─────────────────────────────────────────────

    public function hasDraftContent(): bool
    {
        return (bool) $this->has_draft_content;
    }

    public function isPublished(): bool
    {
        // Coaching không có nội dung → luôn hiện trên app
        return $this->isCoaching() || ($this->published_at !== null && $this->content_published !== null);
    }

    public function isCoaching(): bool
    {
        return $this->kind === self::KIND_COACHING;
    }

    /** Chỉ bài học thông thường (thư viện bài học). */
    public function scopeLibrary(Builder $query): void
    {
        $query->where('kind', self::KIND_LESSON);
    }

    public function contentStatus(): ContentStatus
    {
        if (! $this->isPublished()) {
            return $this->hasDraftContent() ? ContentStatus::Drafting : ContentStatus::Empty;
        }

        return $this->content_updated_at && $this->content_updated_at->gt($this->published_at)
            ? ContentStatus::PublishedWithChanges
            : ContentStatus::Published;
    }

    /** Chép bản nháp sang bản đã xuất bản. */
    public function publish(): void
    {
        $this->forceFill([
            'content_published' => $this->content_draft,
            'published_at' => now(),
        ])->saveQuietly();
    }

    /** Lọc theo trạng thái nội dung bằng SQL (dùng cho bộ lọc bảng). */
    public function scopeWhereContentStatus(Builder $query, ContentStatus $status): void
    {
        match ($status) {
            ContentStatus::Empty => $query->whereNull('published_at')->where('has_draft_content', false),
            ContentStatus::Drafting => $query->whereNull('published_at')->where('has_draft_content', true),
            ContentStatus::Published => $query->whereNotNull('published_at')
                ->where(fn ($q) => $q->whereNull('content_updated_at')->orWhereColumn('content_updated_at', '<=', 'published_at')),
            ContentStatus::PublishedWithChanges => $query->whereNotNull('published_at')
                ->whereColumn('content_updated_at', '>', 'published_at'),
        };
    }

    /** Giảng viên chỉ thấy bài của mình; admin thấy tất cả. */
    public function scopeVisibleTo(Builder $query, ?User $user): void
    {
        if (! $user?->isAdmin()) {
            $query->where('owner_id', $user?->id);
        }
    }

    public function iconUrl(): ?string
    {
        return $this->icon ? MediaStorage::disk()->url($this->icon) : null;
    }

    /** Nhân bản bài (tên, icon, bản nháp) để sửa riêng — bản sao chưa xuất bản. */
    public function duplicate(): self
    {
        $copy = $this->replicate(['content_published', 'published_at', 'content_updated_at']);
        $copy->title = $this->title . ' (bản sao)';
        $copy->owner_id = auth()->id() ?? $this->owner_id;
        $copy->save();

        // Video bài học: bản sao dùng chung file đã xử lý (không cắt lại), đổi id trong khối video của bản nháp
        $map = [];
        // (chỉ video đã xử lý xong — video đang xử lý thì bản sao phải tải lại)
        foreach ($this->videos()->whereIn('id', LessonContent::videoIds($this->content_draft) ?: [0])->get()->filter->isReady() as $video) {
            $newVideo = $video->replicate(['lesson_id']);
            $newVideo->lesson_id = $copy->id;
            $newVideo->hls_key = $video->hls_key;
            $newVideo->save();
            $map[$video->id] = $newVideo->id;
        }
        if ($map) {
            $content = $copy->content_draft;
            foreach ($content['blocks'] ?? [] as $i => $block) {
                if (($block['type'] ?? null) === 'video' && isset($map[$block['data']['video_id'] ?? 0])) {
                    $content['blocks'][$i]['data']['video_id'] = $map[$block['data']['video_id']];
                }
            }
            $copy->forceFill(['content_draft' => $content])->saveQuietly();
        }

        return $copy;
    }

    // ── Quan hệ ──────────────────────────────────────────────

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function pathLessons(): HasMany
    {
        return $this->hasMany(PathLesson::class);
    }

    /** Video tải riêng cho bài (khối "Video" trong nội dung). */
    public function videos(): HasMany
    {
        return $this->hasMany(LessonVideo::class);
    }

    /** Các khóa học đang dùng bài này (qua lộ trình). */
    public function usedInCourses(): Collection
    {
        return Course::query()
            ->whereHas('learningPaths.pathLessons', fn (Builder $query) => $query->where('lesson_id', $this->id))
            ->orderBy('title')
            ->get(['id', 'title']);
    }
}
