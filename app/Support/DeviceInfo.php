<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Thông tin thiết bị app gửi kèm mỗi request qua header:
 *   X-Device-Name: iPhone 15 Pro
 *   X-Device-Platform: ios | android | web
 *   X-Device-OS: 18.1
 *   X-App-Version: 1.0.3
 *   X-Device-Id: <id cố định của máy>
 */
class DeviceInfo
{
    /** @return array<string, string|null> */
    public static function fromRequest(Request $request): array
    {
        $platform = Str::lower((string) $request->header('X-Device-Platform'));

        return [
            'ip_address' => $request->ip(),
            'user_agent' => self::clean($request->userAgent(), 500),
            'device_name' => self::clean($request->header('X-Device-Name'), 255),
            'platform' => in_array($platform, ['ios', 'android', 'web'], true) ? $platform : null,
            'os_version' => self::clean($request->header('X-Device-OS'), 50),
            'app_version' => self::clean($request->header('X-App-Version'), 50),
            'device_id' => self::clean($request->header('X-Device-Id'), 100),
        ];
    }

    /** Tên token Sanctum: tên thiết bị, hoặc nền tảng, hoặc "app". */
    public static function tokenName(Request $request): string
    {
        $info = self::fromRequest($request);

        return $info['device_name'] ?? $info['platform'] ?? 'app';
    }

    /** Mô tả thiết bị gọn cho email: "iPhone 15 Pro · iOS 18.1", hoặc trình duyệt. */
    public static function summary(Request $request): ?string
    {
        $info = self::fromRequest($request);

        $platform = match ($info['platform']) {
            'ios' => 'iOS',
            'android' => 'Android',
            'web' => 'Web',
            default => null,
        };

        $parts = array_filter([
            $info['device_name'],
            trim(($platform ?? '') . ' ' . ($info['os_version'] ?? '')) ?: null,
        ]);

        return $parts ? implode(' · ', $parts) : (Str::limit((string) $info['user_agent'], 80) ?: null);
    }

    private static function clean(?string $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $max - 3);
    }
}
