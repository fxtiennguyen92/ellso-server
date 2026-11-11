<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use ZipArchive;

/**
 * Kiểm tra file tài liệu theo đuôi + nội dung thật, thay cho việc tin MIME:
 * - MIME của EPUB rất thất thường: trình duyệt trên Windows hay gửi rỗng / application/octet-stream,
 *   còn libmagic (nhất là bản đi kèm PHP trên Windows) có thể nhận EPUB là application/zip / octet-stream
 *   → trước đây file EPUB đúng vẫn bị báo "không hợp lệ".
 * - PDF: phần đầu phải có "%PDF". EPUB: phải là zip đọc được và có META-INF/container.xml.
 */
class DocumentFile implements ValidationRule
{
    /** @param  list<string>  $extensions  vd ['pdf', 'epub'] */
    public function __construct(private array $extensions) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            return; // file đã lưu từ trước (chuỗi đường dẫn)
        }

        $ext = strtolower($value->getClientOriginalExtension());
        if (! in_array($ext, $this->extensions, true)) {
            $fail('Chỉ nhận file ' . strtoupper(implode(' / ', $this->extensions)) . '.');

            return;
        }

        if ($ext === 'pdf') {
            $stream = self::open($value);
            $head = $stream ? (string) fread($stream, 1024) : '';
            $stream && fclose($stream);
            if (! str_contains($head, '%PDF')) {
                $fail('File PDF bị hỏng hoặc không phải PDF thật (đổi đuôi từ định dạng khác?).');
            }

            return;
        }

        // EPUB: ZipArchive cần file trên máy → file tạm ở storage S3 thì chép về file tạm cục bộ trước
        [$path, $cleanup] = self::localCopy($value);
        try {
            $zip = new ZipArchive;
            if ($path === null || $zip->open($path) !== true) {
                $fail('File EPUB bị hỏng: không mở được (EPUB thực chất là file zip).');

                return;
            }
            $ok = $zip->locateName('META-INF/container.xml') !== false;
            $zip->close();
            if (! $ok) {
                $fail('File EPUB thiếu META-INF/container.xml — không phải EPUB chuẩn.');
            }
        } finally {
            $cleanup();
        }
    }

    /**
     * Mở luồng đọc file vừa upload. Production để file tạm Livewire trên S3 (BizFly) — getRealPath() khi đó
     * chỉ là key, không đọc được trực tiếp → đọc qua storage.
     *
     * @return resource|null
     */
    private static function open(UploadedFile $file)
    {
        $stream = $file instanceof TemporaryUploadedFile ? $file->readStream() : @fopen($file->getRealPath(), 'rb');

        return is_resource($stream) ? $stream : null;
    }

    /** @return array{0: ?string, 1: \Closure} đường dẫn file đọc được trên máy + hàm dọn file tạm */
    private static function localCopy(UploadedFile $file): array
    {
        $real = $file->getRealPath();
        if ($real && is_file($real)) {
            return [$real, fn () => null];
        }
        $stream = self::open($file);
        if (! $stream) {
            return [null, fn () => null];
        }
        $tmp = tempnam(sys_get_temp_dir(), 'epub');
        $out = fopen($tmp, 'wb');
        stream_copy_to_stream($stream, $out);
        fclose($out);
        fclose($stream);

        return [$tmp, fn () => @unlink($tmp)];
    }
}
