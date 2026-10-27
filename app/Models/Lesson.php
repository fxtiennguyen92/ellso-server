<?php

namespace App\Models;

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

    protected $fillable = [
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
        static::deleted(function (Lesson $lesson) {
            $lesson->pathLessons()->delete();
        });
    }

    // ── Nội dung ─────────────────────────────────────────────

    public function hasDraftContent(): bool
    {
        return (bool) $this->has_draft_content;
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null && $this->content_published !== null;
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

    /** Các khóa học đang dùng bài này (qua lộ trình). */
    public function usedInCourses(): Collection
    {
        return Course::query()
            ->whereHas('learningPaths.pathLessons', fn (Builder $query) => $query->where('lesson_id', $this->id))
            ->orderBy('title')
            ->get(['id', 'title']);
    }
}
