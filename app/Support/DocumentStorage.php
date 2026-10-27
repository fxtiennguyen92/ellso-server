<?php

namespace App\Support;

use App\Models\Document;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Ổ lưu tài liệu riêng tư (config ellso.documents.disk): documents_local (dev) hoặc s3 (production).
 * Mọi file chỉ mở bằng link tạm có chữ ký (S3: presigned URL; local: route /private-docs có chữ ký).
 */
class DocumentStorage
{
    public static function diskName(): string
    {
        return (string) config('ellso.documents.disk', 'documents_local');
    }

    public static function disk(): Filesystem
    {
        return Storage::disk(self::diskName());
    }

    public static function url(string $path, int $minutes, array $options = []): string
    {
        return self::disk()->temporaryUrl($path, now()->addMinutes($minutes), $options);
    }

    /** Link tải về (S3: buộc tải với tên file gốc). */
    public static function downloadUrl(string $path, string $fileName, int $minutes): string
    {
        $options = config('filesystems.disks.' . self::diskName() . '.driver') === 's3'
            ? ['ResponseContentDisposition' => 'attachment; filename="' . addcslashes($fileName, '"\\') . '"']
            : [];

        return self::url($path, $minutes, $options);
    }

    /** Xoá file riêng tư của tài liệu (file gốc video, thư mục HLS, PDF / sách). */
    public static function deleteFor(Document $doc): void
    {
        $disk = self::disk();
        foreach (array_filter([$doc->video_source_path, $doc->file_path]) as $path) {
            $disk->delete($path);
        }
        if ($doc->hls_path) {
            $disk->deleteDirectory($doc->hls_path);
        }
    }
}
