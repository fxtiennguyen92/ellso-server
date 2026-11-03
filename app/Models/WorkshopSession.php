<?php

namespace App\Models;

use App\Models\Concerns\ScheduledSession;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Một buổi (một lần tổ chức) của workshop: ngày giờ, link họp, ghi chú. Học viên đăng ký từng buổi.
 * link_sent_at / link_sent_count: lần cuối admin gửi email link cho người đăng ký.
 */
class WorkshopSession extends Model
{
    use ScheduledSession;

    public const TZ = 'Asia/Ho_Chi_Minh';

    /** App hiện link họp cho người đã đăng ký từ bao nhiêu phút trước giờ bắt đầu. */
    public const LINK_OPENS_MINUTES_BEFORE = 30;

    protected $fillable = ['workshop_id', 'starts_at', 'ends_at', 'meeting_url', 'note', 'cancelled_at', 'link_sent_at', 'link_sent_count'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'link_sent_at' => 'datetime',
            'link_sent_count' => 'integer',
        ];
    }

    public function workshop(): BelongsTo
    {
        return $this->belongsTo(Workshop::class)->withTrashed();
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(WorkshopRegistration::class);
    }

    public function linkAvailableAt(): CarbonInterface
    {
        return $this->starts_at->copy()->subMinutes(self::LINK_OPENS_MINUTES_BEFORE);
    }

    /** Link họp mở cho người đã đăng ký: từ 30 phút trước giờ bắt đầu đến khi kết thúc, buổi chưa huỷ. */
    public function isLinkOpen(): bool
    {
        return ! $this->isCancelled() && $this->meeting_url && now()->gte($this->linkAvailableAt()) && now()->lt($this->ends_at);
    }

    /** Còn nhận đăng ký: chưa huỷ, chưa bắt đầu. */
    public function isRegistrationOpen(): bool
    {
        return ! $this->isCancelled() && $this->starts_at->isFuture();
    }

    /** Số chỗ còn lại; null = không giới hạn. */
    public function seatsLeft(?int $capacity, ?int $registered = null): ?int
    {
        if ($capacity === null) {
            return null;
        }

        return max(0, $capacity - ($registered ?? $this->registrations()->count()));
    }
}
