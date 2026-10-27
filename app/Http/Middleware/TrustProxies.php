<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

/**
 * Production chạy sau CloudFront: IP thật của người dùng nằm trong X-Forwarded-For.
 * Không tin proxy thì $request->ip() là IP của CloudFront → giới hạn theo IP (đăng ký, quên mật khẩu…)
 * bị dùng chung cho mọi người, log thiết bị ghi sai IP.
 *
 * TRUSTED_PROXIES (config app.trusted_proxies): trống = không tin (local), "*" = tin máy gọi trực tiếp
 * (CloudFront → máy chủ, không qua load balancer), hoặc danh sách IP / CIDR cách nhau dấu phẩy.
 * Chỉ tin X-Forwarded-For: Host / Proto không lấy từ header (người dùng có thể tự gửi qua CloudFront);
 * https do FORCE_HTTPS quyết định (xem ForceHttps).
 */
class TrustProxies extends Middleware
{
    protected $headers = Request::HEADER_X_FORWARDED_FOR;

    protected function proxies()
    {
        $proxies = trim((string) config('app.trusted_proxies'));

        if ($proxies === '') {
            return parent::proxies();
        }

        return $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies));
    }
}
