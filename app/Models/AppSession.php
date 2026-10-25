<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một lượt mở app = một phiên. Mở app, hoặc quay lại app sau ≥ 30 phút không có tín hiệu,
 * tạo phiên mới; tín hiệu trong vòng 30 phút chỉ kéo dài phiên (last_seen_at).
 */
class AppSession extends Model
{
    public $timestamps = false;

    /** Phút không có tín hiệu thì phiên kết thúc. */
    public const IDLE_MINUTES = 30;

    public const PLATFORMS = ['ios' => 'iOS', 'android' => 'Android', 'web' => 'Web'];

    protected $fillable = [
        'user_id',
        'device_id',
        'device_name',
        'platform',
        'os_version',
        'app_version',
        'ip_address',
        'started_at',
        'last_seen_at',
        'ping_count',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'ping_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function durationSeconds(): int
    {
        return (int) $this->started_at->diffInSeconds($this->last_seen_at);
    }

    public static function platformLabel(?string $platform): string
    {
        return self::PLATFORMS[$platform] ?? 'Khác';
    }
}
