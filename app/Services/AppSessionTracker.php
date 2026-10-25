<?php

namespace App\Services;

use App\Models\AppSession;
use App\Models\User;
use App\Support\DeviceInfo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Ghi lượt mở app theo phiên 30 phút.
 *
 * App gọi ping khi: mở app, quay lại app từ nền, và mỗi ~60 giây khi đang ở màn hình.
 * - Có phiên của cùng thiết bị còn tín hiệu trong 30 phút → kéo dài phiên đó.
 * - Không có → tạo phiên mới (= 1 lượt mở app).
 */
class AppSessionTracker
{
    /** @return array{0: AppSession, 1: bool} [phiên, có phải lượt mở mới] */
    public function ping(User $user, Request $request): array
    {
        $device = DeviceInfo::fromRequest($request);
        $deviceKey = $device['device_id'] ?? (($device['platform'] ?? '-') . '|' . ($device['device_name'] ?? '-'));

        // Khoá ngắn tránh 2 ping cùng lúc tạo 2 phiên
        return Cache::lock('app-session:' . $user->getKey() . ':' . md5($deviceKey), 5)->block(3, function () use ($user, $device) {
            $now = now();

            $session = $user->appSessions()
                ->where('last_seen_at', '>=', $now->copy()->subMinutes(AppSession::IDLE_MINUTES))
                ->when(
                    $device['device_id'],
                    fn ($query) => $query->where('device_id', $device['device_id']),
                    fn ($query) => $query->whereNull('device_id')
                        ->where('platform', $device['platform'])
                        ->where('device_name', $device['device_name']),
                )
                ->latest('last_seen_at')
                ->first();

            if ($session) {
                $session->forceFill([
                    'last_seen_at' => $now,
                    'ping_count' => $session->ping_count + 1,
                    // app có thể vừa cập nhật phiên bản
                    'app_version' => $device['app_version'] ?? $session->app_version,
                ])->save();

                return [$session, false];
            }

            $session = $user->appSessions()->create([
                'device_id' => $device['device_id'],
                'device_name' => $device['device_name'],
                'platform' => $device['platform'],
                'os_version' => $device['os_version'],
                'app_version' => $device['app_version'],
                'ip_address' => $device['ip_address'],
                'started_at' => $now,
                'last_seen_at' => $now,
            ]);

            return [$session, true];
        });
    }
}
