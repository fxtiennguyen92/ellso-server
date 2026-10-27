<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Ổ lưu ảnh công khai (banner, ảnh bìa, icon bài học, ảnh trong nội dung…) — config ellso.media.disk (MEDIA_DISK).
 *  - Local: "public" (storage/app/public, link /storage/… theo domain đang gọi, xem UseRequestHostForPublicFiles).
 *  - Production: "media_s3" (S3, thư mục media/, link qua CloudFront = AWS_URL).
 * Mọi chỗ đọc / ghi / tạo link ảnh công khai đều đi qua class này, không gọi thẳng disk('public').
 */
class MediaStorage
{
    public static function diskName(): string
    {
        return (string) config('ellso.media.disk', 'public');
    }

    public static function disk(): Filesystem
    {
        return Storage::disk(self::diskName());
    }

    public static function url(?string $path): ?string
    {
        return is_string($path) && $path !== '' ? self::disk()->url($path) : null;
    }

    /** Link gốc (kết thúc bằng /) — trình soạn bài học dùng để nhận diện ảnh của hệ thống. */
    public static function baseUrl(): string
    {
        return rtrim(self::disk()->url(''), '/') . '/';
    }

    public static function isS3(): bool
    {
        return config('filesystems.disks.' . self::diskName() . '.driver') === 's3';
    }

    /**
     * Visibility cho ô tải ảnh của Filament (FileUpload).
     * S3: "private" → ô xem trước dùng link tạm của S3 (bucket đã bật CORS cho CMS) thay vì link CloudFront.
     * Bucket tắt ACL nên visibility không đổi quyền file; ảnh vẫn xem công khai qua CloudFront.
     */
    public static function uploadVisibility(): string
    {
        return self::isS3() ? 'private' : 'public';
    }
}
