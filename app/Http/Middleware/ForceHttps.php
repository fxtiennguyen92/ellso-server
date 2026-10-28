<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Production: CloudFront / Load Balancer nhận https rồi gọi máy chủ bằng http.
 * FORCE_HTTPS=true → coi mọi request là https: link / redirect của CMS đúng https, link có chữ ký
 * (video HLS, file tạm Livewire) tạo và kiểm tra cùng một scheme. Local để trống (false).
 * Sau Load Balancer: người dùng mở http:// (Load Balancer gửi X-Forwarded-Proto: http) → chuyển sang https://.
 * Request không có header này (health check, CloudFront) không bị chuyển.
 */
class ForceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('app.force_https')) {
            if ($request->headers->get('X-Forwarded-Proto') === 'http' && $request->isMethodSafe()) {
                return redirect()->to('https://' . $request->getHttpHost() . $request->getRequestUri(), 301);
            }

            $request->server->set('HTTPS', 'on');
            URL::forceScheme('https');
        }

        return $next($request);
    }
}
