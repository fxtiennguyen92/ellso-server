<?php

namespace App\Jobs;

use App\Enums\DocumentType;
use App\Enums\VideoStatus;
use App\Models\Document;
use App\Support\DocumentStorage;
use App\Support\EpubPreview;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Tạo bản đọc thử của sách, lưu riêng tư trên ổ tài liệu: previews/{id}/{ngẫu nhiên}.{pdf|epub}.
 * - PDF: qpdf cắt N trang đầu (QPDF_BINARY). Sách ít trang hơn N → lấy hết số trang có.
 * - EPUB: giữ N chương đầu (App\Support\EpubPreview, không cần chương trình ngoài).
 * Xong thì xoá bản đọc thử cũ. Trạng thái dùng chung enum với video (chờ / đang xử lý / sẵn sàng / lỗi).
 */
class GenerateBookPreview implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $documentId) {}

    public function handle(): void
    {
        $doc = Document::withTrashed()->find($this->documentId);
        if (! $doc || $doc->trashed() || $doc->type !== DocumentType::Book || ! $doc->preview_enabled || ! $doc->file_path) {
            return;
        }

        $doc->forceFill(['preview_status' => VideoStatus::Processing, 'preview_error' => null])->saveQuietly();
        $work = storage_path('app/tmp/preview-' . $doc->id . '-' . Str::random(6));
        File::ensureDirectoryExists($work);

        try {
            $disk = DocumentStorage::disk();
            $epub = $doc->file_format === 'epub';
            $source = $work . '/source.' . ($epub ? 'epub' : 'pdf');
            $target = $work . '/preview.' . ($epub ? 'epub' : 'pdf');

            $in = $disk->readStream($doc->file_path);
            if (! $in) {
                throw new RuntimeException('Không đọc được file sách.');
            }
            $out = fopen($source, 'wb');
            stream_copy_to_stream($in, $out);
            fclose($out);
            is_resource($in) && fclose($in);

            if ($epub) {
                ['kept' => $kept, 'total' => $total] = EpubPreview::make($source, $target, max(1, (int) $doc->preview_chapters));
            } else {
                $total = (int) trim($this->run([config('ellso.documents.qpdf'), '--show-npages', $source]));
                if ($total < 1) {
                    throw new RuntimeException('Không đọc được số trang của file PDF.');
                }
                $kept = min(max(1, (int) $doc->preview_pages), $total);
                $this->run([config('ellso.documents.qpdf'), '--warning-exit-0', '--empty', '--pages', $source, '1-' . $kept, '--', $target]);
            }

            $path = 'previews/' . $doc->id . '/' . Str::lower(Str::random(12)) . '.' . ($epub ? 'epub' : 'pdf');
            $stream = fopen($target, 'rb');
            $disk->writeStream($path, $stream);
            is_resource($stream) && fclose($stream);

            // Trong lúc xử lý, admin có thể đã tắt đọc thử / thay file → bỏ kết quả này
            $fresh = Document::withTrashed()->find($doc->id);
            if (! $fresh || $fresh->trashed() || ! $fresh->preview_enabled || $fresh->file_path !== $doc->file_path) {
                $disk->delete($path);

                return;
            }

            $old = $fresh->preview_path;
            $fresh->forceFill([
                'preview_path' => $path,
                'preview_status' => VideoStatus::Ready,
                'preview_error' => null,
                'preview_count' => $kept,
                'source_count' => $total,
            ])->saveQuietly();

            if ($old && $old !== $path) {
                $disk->delete($old);
            }
        } catch (Throwable $e) {
            Log::error('Tạo bản đọc thử sách thất bại', ['document_id' => $doc->id, 'error' => $e->getMessage()]);
            $doc->forceFill(['preview_status' => VideoStatus::Failed, 'preview_error' => Str::limit($e->getMessage(), 1000)])->saveQuietly();
        } finally {
            File::deleteDirectory($work);
        }
    }

    public function failed(?Throwable $e): void
    {
        $doc = Document::withTrashed()->find($this->documentId);
        if ($doc && in_array($doc->preview_status, [VideoStatus::Pending, VideoStatus::Processing], true)) {
            $doc->forceFill([
                'preview_status' => VideoStatus::Failed,
                'preview_error' => 'Xử lý bị gián đoạn. Bấm "Tạo lại bản đọc thử".',
            ])->saveQuietly();
        }
    }

    private function run(array $command): string
    {
        $process = new Process($command);
        $process->setTimeout($this->timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            $error = trim($process->getErrorOutput()) ?: trim($process->getOutput()) ?: 'qpdf lỗi';
            throw new RuntimeException(mb_substr($error, -800));
        }

        return $process->getOutput();
    }
}
