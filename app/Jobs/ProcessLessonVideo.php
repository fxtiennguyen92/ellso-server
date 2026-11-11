<?php

namespace App\Jobs;

use App\Enums\VideoStatus;
use App\Models\LessonVideo;
use App\Support\DocumentStorage;
use App\Support\HlsEncoder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Cắt video bài học thành HLS (giống video tài liệu): lesson-videos/{lesson}/{video}/{random}/… Xong thì xoá file gốc. */
class ProcessLessonVideo implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public int $videoId) {}

    public function handle(): void
    {
        $video = LessonVideo::find($this->videoId);
        if (! $video || ! $video->video_source_path) {
            return;
        }

        $video->forceFill(['video_status' => VideoStatus::Processing, 'video_error' => null])->saveQuietly();

        try {
            $hlsPath = 'lesson-videos/' . $video->lesson_id . '/' . $video->id . '/' . Str::lower(Str::random(10));
            $result = (new HlsEncoder($this->timeout))->encode($video->video_source_path, $hlsPath);

            $source = $video->video_source_path;
            $video->forceFill([
                'hls_path' => $hlsPath,
                'hls_key' => $result['key'],
                'hls_variants' => $result['variants'],
                'duration_seconds' => $result['duration'],
                'video_status' => VideoStatus::Ready,
                'video_source_path' => null,
                'video_error' => null,
            ])->saveQuietly();
            DocumentStorage::disk()->delete($source);
        } catch (Throwable $e) {
            Log::error('Xử lý video bài học thất bại', ['lesson_video_id' => $video->id, 'error' => $e->getMessage()]);
            $video->forceFill(['video_status' => VideoStatus::Failed, 'video_error' => Str::limit($e->getMessage(), 1000)])->saveQuietly();
        }
    }

    /** Job bị ngắt (máy chủ khởi động lại) → đánh dấu lỗi để giảng viên tải lại, không kẹt ở "Đang xử lý". */
    public function failed(?Throwable $e): void
    {
        $video = LessonVideo::find($this->videoId);
        if ($video && in_array($video->video_status, [VideoStatus::Pending, VideoStatus::Processing], true)) {
            $video->forceFill([
                'video_status' => VideoStatus::Failed,
                'video_error' => 'Xử lý bị gián đoạn (máy chủ khởi động lại). Vui lòng tải lại video.',
            ])->saveQuietly();
        }
    }
}
