<?php

namespace App\Jobs;

use App\Enums\VideoStatus;
use App\Models\Document;
use App\Support\DocumentStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Cắt video tài liệu thành HLS (480p + 720p, không phóng to quá độ phân giải gốc), mã hoá AES-128,
 * đẩy lên ổ tài liệu (S3 / local): {hls_path}/master.m3u8, {hls_path}/{480|720}/index.m3u8 + seg_XXXX.ts.
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
        $work = storage_path('app/tmp/hls-' . $doc->id . '-' . Str::random(6));
        File::ensureDirectoryExists($work . '/out');

        try {
            $disk = DocumentStorage::disk();
            $source = $work . '/source';
            $in = $disk->readStream($doc->video_source_path);
            if (! $in) {
                throw new RuntimeException('Không đọc được file video gốc.');
            }
            $outStream = fopen($source, 'wb');
            stream_copy_to_stream($in, $outStream);
            fclose($outStream);
            is_resource($in) && fclose($in);

            [$duration, $height] = $this->probe($source);

            // Khoá AES-128 + IV ngẫu nhiên; URI "key.key" được thay bằng link có chữ ký khi phát
            $key = random_bytes(16);
            file_put_contents($work . '/enc.key', $key);
            file_put_contents($work . '/keyinfo', "key.key\n{$work}/enc.key\n" . bin2hex(random_bytes(16)) . "\n");

            $variants = [];
            foreach (config('ellso.documents.hls_variants') as $h => [$w, $vb, $ab]) {
                if ($h > 480 && $height && $height < $h) {
                    continue; // không phóng to video thấp hơn 720p (480p luôn có)
                }
                $dir = $work . '/out/' . $h;
                File::ensureDirectoryExists($dir);
                $this->run([
                    config('ellso.documents.ffmpeg'), '-hide_banner', '-loglevel', 'error', '-y', '-i', $source,
                    '-vf', "scale=-2:{$h}",
                    '-c:v', 'libx264', '-preset', 'veryfast', '-profile:v', 'main', '-pix_fmt', 'yuv420p', // yuv420p: điện thoại nào cũng phát được
                    '-b:v', $vb, '-maxrate', $vb, '-bufsize', ((int) $vb * 2) . 'k',
                    '-g', '48', '-keyint_min', '48', '-sc_threshold', '0',
                    '-c:a', 'aac', '-b:a', $ab, '-ac', '2',
                    '-hls_time', (string) config('ellso.documents.hls_segment_seconds'),
                    '-hls_playlist_type', 'vod',
                    '-hls_key_info_file', $work . '/keyinfo',
                    '-hls_segment_filename', $dir . '/seg_%04d.ts',
                    $dir . '/index.m3u8',
                ]);
                $variants[] = ['height' => $h, 'width' => $w, 'bandwidth' => ((int) $vb + (int) $ab) * 1000];
            }

            // Đẩy lên ổ tài liệu (thư mục mới mỗi lần xử lý → thay video không ảnh hưởng người đang xem bản cũ)
            $hlsPath = 'videos/' . $doc->id . '/' . Str::lower(Str::random(10));
            foreach (File::allFiles($work . '/out') as $file) {
                $stream = fopen($file->getPathname(), 'rb');
                $disk->writeStream($hlsPath . '/' . str_replace('\\', '/', $file->getRelativePathname()), $stream);
                is_resource($stream) && fclose($stream);
            }

            $oldHls = $doc->hls_path;
            $oldSource = $doc->video_source_path;
            $doc->forceFill([
                'hls_path' => $hlsPath,
                'hls_key' => base64_encode($key),
                'hls_variants' => $variants,
                'duration_seconds' => (int) round($duration),
                'video_status' => VideoStatus::Ready,
                'video_source_path' => null,
                'video_error' => null,
            ])->saveQuietly();

            if ($oldHls) {
                $disk->deleteDirectory($oldHls);
            }
            $disk->delete($oldSource);
        } catch (Throwable $e) {
            Log::error('Xử lý video tài liệu thất bại', ['document_id' => $doc->id, 'error' => $e->getMessage()]);
            $doc->forceFill(['video_status' => VideoStatus::Failed, 'video_error' => Str::limit($e->getMessage(), 1000)])->saveQuietly();
        } finally {
            File::deleteDirectory($work);
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

    /** @return array{0: float, 1: int|null} thời lượng (giây), chiều cao video */
    private function probe(string $file): array
    {
        $json = $this->run([
            config('ellso.documents.ffprobe'), '-v', 'error', '-select_streams', 'v:0',
            '-show_entries', 'stream=height:format=duration', '-of', 'json', $file,
        ]);
        $data = json_decode($json, true) ?: [];
        if (empty($data['streams'])) {
            throw new RuntimeException('File không phải video hợp lệ.');
        }

        return [(float) ($data['format']['duration'] ?? 0), isset($data['streams'][0]['height']) ? (int) $data['streams'][0]['height'] : null];
    }

    private function run(array $command): string
    {
        $process = new Process($command);
        $process->setTimeout($this->timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            $error = trim($process->getErrorOutput()) ?: 'ffmpeg lỗi';
            throw new RuntimeException(mb_substr($error, -800)); // giữ phần cuối (dòng lỗi thật)
        }

        return $process->getOutput();
    }
}
