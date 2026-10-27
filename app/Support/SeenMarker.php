<?php

namespace App\Support;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * "Đã xem đến lúc nào" theo từng người dùng CMS (bảng user_seen_markers).
 * Mục có thời gian sau mốc này = mới với người đó.
 */
class SeenMarker
{
    public const PLACEMENT_ATTEMPTS = 'placement_attempts';

    public static function get(?User $user, string $key): ?CarbonImmutable
    {
        if (! $user) {
            return null;
        }

        $value = DB::table('user_seen_markers')->where('user_id', $user->id)->where('key', $key)->value('seen_at');

        return $value ? CarbonImmutable::parse($value, 'UTC') : null;
    }

    /** Đánh dấu đã xem tới bây giờ; trả về mốc cũ (để tô các mục mới trong lần xem này). */
    public static function touch(User $user, string $key): ?CarbonImmutable
    {
        $previous = self::get($user, $key);

        DB::table('user_seen_markers')->upsert(
            [['user_id' => $user->id, 'key' => $key, 'seen_at' => now('UTC')->toDateTimeString()]],
            ['user_id', 'key'],
            ['seen_at'],
        );

        return $previous;
    }
}
