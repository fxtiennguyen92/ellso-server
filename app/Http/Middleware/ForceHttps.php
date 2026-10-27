<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Production: CloudFront nhận https rồi gọi máy chủ bằng http, và không gửi header X-Forwarded-Proto.
 * FORCE_HTTPS=true → coi mọi request là https: link / redirect của CMS đúng https, link có chữ ký
 * (video HLS, file tạm Livewire) tạo và kiểm tra cùng một scheme. Local để trống (false).
 */
class ForceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('app.force_https')) {
            $request->server->set('HTTPS', 'on');
            URL::forceScheme('https');
        }

        return $next($request);
    }
}
