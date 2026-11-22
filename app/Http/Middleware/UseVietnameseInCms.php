<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CMS hiển thị tiếng Việt (nút, phân trang, bộ lọc, đăng nhập… của Filament).
 * Chỉ áp cho panel admin, API vẫn theo APP_LOCALE nên không cần sửa .env trên server.
 */
class UseVietnameseInCms
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale('vi');

        return $next($request);
    }
}
