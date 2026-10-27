<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Enums\DocumentType;
use App\Enums\VideoStatus;
use App\Jobs\ProcessDocumentVideo;
use App\Models\Document;
use App\Support\DocumentStorage;

/** Xử lý chung khi tạo / sửa tài liệu: giá bán, thông tin file, đưa video vào hàng đợi cắt HLS. */
trait SavesDocument
{
    protected function normalizeData(array $data): array
    {
        $type = $data['type'] ?? $this->getRecord()?->type;
        $type = $type instanceof DocumentType ? $type : DocumentType::tryFrom((string) $type);

        if ($type !== DocumentType::Book || ! ($data['sell'] ?? false) || ($data['is_free'] ?? false)) {
            $data['price'] = null;
        }
        if ($data['is_free'] ?? false) {
            $data['requires_membership'] = false;
        }
        unset($data['sell']);

        // Tác giả: giảng viên (author_id) hoặc tác giả ngoài (tên) — chỉ giữ một
        $kind = $this->data['author_kind'] ?? 'teacher';
        if ($kind === 'external') {
            $data['author_id'] = null;
        } else {
            $data['author'] = null;
        }

        return $data;
    }

    /** Sau khi lưu: cập nhật dung lượng / định dạng file; video mới → chờ xử lý + đưa vào hàng đợi. */
    protected function afterDocumentSaved(Document $doc): void
    {
        if ($doc->file_path && ($doc->wasChanged('file_path') || $doc->wasRecentlyCreated || ! $doc->file_size)) {
            $disk = DocumentStorage::disk();
            $doc->forceFill([
                'file_size' => $disk->exists($doc->file_path) ? $disk->size($doc->file_path) : null,
                'file_format' => strtolower(pathinfo($doc->file_name ?: $doc->file_path, PATHINFO_EXTENSION)) === 'epub' ? 'epub' : 'pdf',
            ])->saveQuietly();
        }

        if ($doc->type === DocumentType::Video && $doc->video_source_path
            && ($doc->wasRecentlyCreated || $doc->wasChanged('video_source_path'))) {
            $doc->forceFill(['video_status' => VideoStatus::Pending, 'video_error' => null])->saveQuietly();
            ProcessDocumentVideo::dispatch($doc->id);
        }
    }
}
