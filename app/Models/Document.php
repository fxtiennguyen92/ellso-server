<?php

namespace App\Models;

use App\Enums\ChapterColor;
use App\Enums\DocumentType;
use App\Enums\VideoStatus;
use App\Models\Concerns\CleansUpFiles;
use App\Support\DocumentStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * Tài liệu: Video (HLS), PDF, Sách. Quyền xem: App\Services\DocumentAccess.
 * File gốc / HLS / PDF / sách nằm trên ổ riêng tư DocumentStorage::disk().
 */
class Document extends Model
{
    use CleansUpFiles, SoftDeletes;

    /** Ảnh bìa trên disk public: xoá khi bỏ / thay ảnh hoặc xoá tài liệu. */
    protected array $fileAttributes = ['cover_image'];

    protected bool $purgeFilesOnSoftDelete = true;

    protected $fillable = [
        'type', 'title', 'author', 'author_id', 'description', 'document_topic_id', 'cover_color', 'cover_image',
        'is_free', 'requires_membership', 'price', 'allow_download',
        'file_path', 'file_name', 'file_format', 'file_size',
        'video_source_path', 'video_status', 'video_error', 'hls_path', 'hls_key', 'hls_variants', 'duration_seconds',
        'status', 'published_at', 'created_by_id',
    ];

    protected $hidden = ['hls_key'];

    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'cover_color' => ChapterColor::class,
            'is_free' => 'boolean',
            'requires_membership' => 'boolean',
            'allow_download' => 'boolean',
            'price' => 'integer',
            'file_size' => 'integer',
            'video_status' => VideoStatus::class,
            'hls_key' => 'encrypted',
            'hls_variants' => 'array',
            'duration_seconds' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Document $doc) {
            if ($doc->status === 'published' && ! $doc->published_at) {
                $doc->published_at = now();
            }
            // Tác giả là giảng viên → tên lấy theo tài khoản (đổi tên giảng viên thì tài liệu tự đổi theo)
            if ($doc->author_id) {
                $doc->author = null;
            }
            // Chỉ Sách được bán riêng / tải về
            if ($doc->type !== DocumentType::Book) {
                $doc->price = null;
                $doc->allow_download = false;
            }
        });

        // Thay file PDF / sách → xoá file cũ
        static::updated(function (Document $doc) {
            if ($doc->wasChanged('file_path') && ($old = $doc->getOriginal('file_path'))) {
                DocumentStorage::disk()->delete($old);
            }
        });

        // Xoá tài liệu → xoá file riêng tư (file gốc, HLS, PDF / sách)
        static::deleted(function (Document $doc) {
            DocumentStorage::deleteFor($doc);
        });
    }

    /** Tác giả là giảng viên trong hệ thống (null = tác giả ngoài, chỉ có tên ở cột `author`). */
    public function authorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** Tên tác giả hiển thị: tên giảng viên hoặc tên tác giả ngoài. */
    public function authorName(): ?string
    {
        return $this->author_id ? $this->authorUser?->name : $this->author;
    }

    /** Lọc theo tên tác giả (cả giảng viên lẫn tác giả ngoài). */
    public function scopeWhereAuthorLike($query, string $term)
    {
        return $query->where(fn ($w) => $w->where('author', 'like', "%{$term}%")
            ->orWhereHas('authorUser', fn ($u) => $u->where('name', 'like', "%{$term}%")));
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(DocumentTopic::class, 'document_topic_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(DocumentLink::class);
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'document_links')->whereNotNull('document_links.course_id');
    }

    public function lessons(): BelongsToMany
    {
        return $this->belongsToMany(Lesson::class, 'document_links')->whereNotNull('document_links.lesson_id');
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(DocumentPurchase::class);
    }

    public function savedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'document_saves')->withPivot('created_at');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function scopePublished(Builder $query): void
    {
        $query->where('status', 'published')
            ->where(fn ($q) => $q->where('type', '!=', DocumentType::Video->value)->orWhere('video_status', VideoStatus::Ready->value));
    }

    public function isPurchasable(): bool
    {
        return $this->type === DocumentType::Book && $this->price !== null;
    }

    public function coverUrl(): ?string
    {
        return $this->cover_image ? Storage::disk('public')->url($this->cover_image) : null;
    }

    /** "1:12:05" / "10:24" */
    public function durationLabel(): ?string
    {
        if (! $this->duration_seconds) {
            return null;
        }
        $h = intdiv($this->duration_seconds, 3600);
        $m = intdiv($this->duration_seconds % 3600, 60);
        $s = $this->duration_seconds % 60;

        return $h ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }
}
