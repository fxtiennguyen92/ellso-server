<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
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

        $path = $value->getRealPath();
        if ($ext === 'pdf') {
            $head = (string) @file_get_contents($path, false, null, 0, 1024);
            if (! str_contains($head, '%PDF')) {
                $fail('File PDF bị hỏng hoặc không phải PDF thật (đổi đuôi từ định dạng khác?).');
            }

            return;
        }

        // EPUB
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            $fail('File EPUB bị hỏng: không mở được (EPUB thực chất là file zip).');

            return;
        }
        $ok = $zip->locateName('META-INF/container.xml') !== false;
        $zip->close();
        if (! $ok) {
            $fail('File EPUB thiếu META-INF/container.xml — không phải EPUB chuẩn.');
        }
    }
}
