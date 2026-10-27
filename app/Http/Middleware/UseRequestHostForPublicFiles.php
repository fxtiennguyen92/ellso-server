<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Link file trên disk "public" (banner, ảnh bìa, ảnh trong bài…) theo đúng domain + cổng đang được gọi,
 * thay vì APP_URL.
 *
 * Lý do: CMS (CMS_DOMAIN, VD localhost:8000 / cms.ellso.edu.vn) và API (API_DOMAIN) chạy chung một
 * thư mục public. Nếu link ảnh trỏ sang domain khác, trình duyệt chặn khi ô tải ảnh của Filament
 * đọc file (khác origin) → ô ảnh kẹt "Loading", không xoá được. App gọi API cũng nhận link theo domain API.
 * Chạy bằng lệnh (queue, mail) thì vẫn dùng APP_URL như cũ.
 * Production dùng S3 (MEDIA_DISK=media_s3, link CloudFront) nên middleware này không ảnh hưởng gì ở đó.
 */
class UseRequestHostForPublicFiles
{
    public function handle(Request $request, Closure $next): Response
    {
        config(['filesystems.disks.public.url' => $request->getSchemeAndHttpHost() . '/storage']);
        Storage::forgetDisk('public');

        return $next($request);
    }
}
