<?php

namespace App\Models;

use App\Enums\UserLogEvent;
use App\Support\DeviceInfo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

class UserLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'actor_id',
        'event',
        'ip_address',
        'user_agent',
        'device_name',
        'platform',
        'os_version',
        'app_version',
        'device_id',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'event' => UserLogEvent::class,
            'meta' => 'array',
        ];
    }

    /**
     * Ghi log cho user. Có request → lấy IP / thiết bị từ request.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function record(
        User $user,
        UserLogEvent $event,
        ?Request $request = null,
        array $meta = [],
        ?User $actor = null,
    ): self {
        return static::create([
            'user_id' => $user->getKey(),
            'actor_id' => $actor?->getKey(),
            'event' => $event,
            'meta' => $meta ?: null,
            ...($request ? DeviceInfo::fromRequest($request) : []),
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** Mô tả thiết bị gọn: "iPhone 15 Pro · iOS 18.1 · app 1.0.3" */
    public function deviceSummary(): ?string
    {
        $platform = match ($this->platform) {
            'ios' => 'iOS',
            'android' => 'Android',
            'web' => 'Web',
            default => null,
        };

        $parts = array_filter([
            $this->device_name,
            trim(($platform ?? '') . ' ' . ($this->os_version ?? '')) ?: null,
            $this->app_version ? 'app ' . $this->app_version : null,
        ]);

        return $parts ? implode(' · ', $parts) : null;
    }
}
