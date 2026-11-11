<?php

namespace App\Jobs;

use App\Enums\VideoStatus;
use App\Models\Document;
use App\Support\DocumentStorage;
use App\Support\HlsEncoder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Cắt video tài liệu thành HLS (App\Support\HlsEncoder: 480p + 720p, mã hoá AES-128),
 * đẩy lên ổ tài liệu (S3 / local): {hls_path}/{480|720}/index.m3u8 + seg_XXXX.ts.
 * Khoá AES lưu trong DB (mã hoá bằng APP_KEY), chỉ trả qua route có chữ ký + kiểm tra quyền (HlsController).
 * Xong thì xoá file gốc và thư mục HLS cũ (nếu thay video).
 *
 * Cần ffmpeg + ffprobe trên server (FFMPEG_BINARY / FFPROBE_BINARY) và queue worker đang chạy.
 */
class ProcessDocumentVideo implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public int $documentId) {}

    public function handle(): void
    {
        $doc = Document::withTrashed()->find($this->documentId);
        if (! $doc || ! $doc->video_source_path || $doc->trashed()) {
            return;
        }

        $doc->forceFill(['video_status' => VideoStatus::Processing, 'video_error' => null])->saveQuietly();

        try {
            // Thư mục mới mỗi lần xử lý → thay video không ảnh hưởng người đang xem bản cũ
            $hlsPath = 'videos/' . $doc->id . '/' . Str::lower(Str::random(10));
            $result = (new HlsEncoder($this->timeout))->encode($doc->video_source_path, $hlsPath);

            $oldHls = $doc->hls_path;
            $oldSource = $doc->video_source_path;
            $doc->forceFill([
                'hls_path' => $hlsPath,
                'hls_key' => $result['key'],
                'hls_variants' => $result['variants'],
                'duration_seconds' => $result['duration'],
                'video_status' => VideoStatus::Ready,
                'video_source_path' => null,
                'video_error' => null,
            ])->saveQuietly();

            $disk = DocumentStorage::disk();
            if ($oldHls) {
                $disk->deleteDirectory($oldHls);
            }
            $disk->delete($oldSource);
        } catch (Throwable $e) {
            Log::error('Xử lý video tài liệu thất bại', ['document_id' => $doc->id, 'error' => $e->getMessage()]);
            $doc->forceFill(['video_status' => VideoStatus::Failed, 'video_error' => Str::limit($e->getMessage(), 1000)])->saveQuietly();
        }
    }

    /**
     * Job bị ngắt giữa chừng (máy chủ khởi động lại / deploy khi đang cắt video) → hàng đợi đánh dấu thất bại
     * sau DB_QUEUE_RETRY_AFTER giây. Chuyển tài liệu sang "Lỗi" để admin biết mà tải lại video, không kẹt ở "Đang xử lý".
     */
    public function failed(?Throwable $e): void
    {
        $doc = Document::withTrashed()->find($this->documentId);
        if ($doc && in_array($doc->video_status, [VideoStatus::Pending, VideoStatus::Processing], true)) {
            $doc->forceFill([
                'video_status' => VideoStatus::Failed,
                'video_error' => 'Xử lý bị gián đoạn (máy chủ khởi động lại). Vui lòng tải lại video.',
            ])->saveQuietly();
        }
    }
}
