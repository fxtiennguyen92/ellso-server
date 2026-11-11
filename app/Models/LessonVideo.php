<?php

namespace App\Models;

use App\Enums\VideoStatus;
use App\Support\DocumentStorage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Video tải riêng cho một bài học (khối "Video" trong nội dung). Cắt HLS bằng job ProcessLessonVideo.
 * Xoá bản ghi → xoá file gốc + thư mục HLS (khi không còn bài nhân bản nào dùng chung).
 */
class LessonVideo extends Model
{
    protected $fillable = [
        'lesson_id', 'original_name', 'video_source_path', 'video_status', 'video_error',
        'hls_path', 'hls_key', 'hls_variants', 'duration_seconds', 'uploaded_by_id',
    ];

    protected $hidden = ['hls_key'];

    protected function casts(): array
    {
        return [
            'video_status' => VideoStatus::class,
            'hls_key' => 'encrypted',
            'hls_variants' => 'array',
            'duration_seconds' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Xoá file khi không còn bản ghi nào dùng (bài nhân bản dùng chung file đã xử lý)
        static::deleted(function (LessonVideo $video) {
            $disk = DocumentStorage::disk();
            if ($video->video_source_path && static::where('video_source_path', $video->video_source_path)->doesntExist()) {
                $disk->delete($video->video_source_path);
            }
            if ($video->hls_path && static::where('hls_path', $video->hls_path)->doesntExist()) {
                $disk->deleteDirectory($video->hls_path);
            }
        });
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function isReady(): bool
    {
        return $this->video_status === VideoStatus::Ready && $this->hls_path !== null;
    }

    /** Trạng thái cho trình soạn: {id, status, label, duration, name, error}. */
    public function editorState(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->video_status->value,
            'label' => $this->video_status->getLabel(),
            'duration' => $this->duration_seconds,
            'name' => $this->original_name,
            'error' => $this->video_error,
        ];
    }
}
