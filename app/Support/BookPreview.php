<?php

namespace App\Support;

use App\Enums\DocumentType;
use App\Enums\VideoStatus;
use App\Jobs\GenerateBookPreview;
use App\Models\Document;

/**
 * Bản đọc thử của sách (N trang PDF / N chương EPUB đầu): bật → tạo (hàng đợi), tắt → xoá file.
 * Gọi sau khi lưu sách trên CMS, hoặc từ nút "Tạo lại bản đọc thử".
 */
class BookPreview
{
    /** Các trường mà đổi thì phải tạo lại bản đọc thử. */
    public const TRIGGERS = ['file_path', 'file_format', 'preview_enabled', 'preview_pages', 'preview_chapters'];

    public static function refresh(Document $doc): void
    {
        if ($doc->type !== DocumentType::Book) {
            return;
        }

        if (! $doc->preview_enabled || ! $doc->file_path) {
            self::clear($doc);

            return;
        }

        $doc->forceFill(['preview_status' => VideoStatus::Pending, 'preview_error' => null])->saveQuietly();
        GenerateBookPreview::dispatch($doc->id);
    }

    /** Xoá file đọc thử + thông tin đi kèm. */
    public static function clear(Document $doc): void
    {
        if ($doc->preview_path) {
            DocumentStorage::disk()->delete($doc->preview_path);
        }
        $doc->forceFill([
            'preview_path' => null, 'preview_status' => null, 'preview_error' => null,
            'preview_count' => null, 'source_count' => null,
        ])->saveQuietly();
    }

    public static function available(Document $doc): bool
    {
        return $doc->type === DocumentType::Book && $doc->preview_enabled
            && $doc->preview_status === VideoStatus::Ready && $doc->preview_path;
    }

    /** Thông tin cho app: {available, pages|chapters}; null với video / PDF. */
    public static function present(Document $doc): ?array
    {
        if ($doc->type !== DocumentType::Book) {
            return null;
        }
        $available = self::available($doc);
        $unit = $doc->file_format === 'epub' ? 'chapters' : 'pages';

        return [
            'available' => $available,
            $unit => $available ? $doc->preview_count : null,
        ];
    }
}
