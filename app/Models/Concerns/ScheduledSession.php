<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Buổi có giờ bắt đầu / kết thúc và có thể huỷ (buổi huấn luyện, buổi workshop).
 * Cột: starts_at, ends_at (UTC), cancelled_at. Hiển thị theo giờ Việt Nam.
 */
trait ScheduledSession
{
    /** Chưa bắt đầu và chưa huỷ. */
    public function scopeUpcoming(Builder $query): void
    {
        $query->whereNull('cancelled_at')->where('starts_at', '>', now());
    }

    /** Chưa kết thúc và chưa huỷ (gồm buổi đang diễn ra). */
    public function scopeNotEnded(Builder $query): void
    {
        $query->whereNull('cancelled_at')->where('ends_at', '>', now());
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /** upcoming | ongoing | past | cancelled */
    public function state(): string
    {
        return match (true) {
            $this->isCancelled() => 'cancelled',
            $this->starts_at->isFuture() => 'upcoming',
            $this->ends_at->isFuture() => 'ongoing',
            default => 'past',
        };
    }

    public function stateLabel(): string
    {
        return ['cancelled' => 'Đã huỷ', 'upcoming' => 'Sắp diễn ra', 'ongoing' => 'Đang diễn ra', 'past' => 'Đã diễn ra'][$this->state()];
    }

    public function stateColor(): string
    {
        return ['cancelled' => 'danger', 'upcoming' => 'success', 'ongoing' => 'info', 'past' => 'gray'][$this->state()];
    }

    /** "T7, 15/11/2026" */
    public function dateLabel(): string
    {
        $d = $this->starts_at->timezone(self::TZ);
        $weekday = ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'][$d->dayOfWeek];

        return $weekday . ', ' . $d->format('d/m/Y');
    }

    /** "18:00 – 21:00" */
    public function timeLabel(): string
    {
        return $this->starts_at->timezone(self::TZ)->format('H:i') . ' – ' . $this->ends_at->timezone(self::TZ)->format('H:i');
    }

    /** "18:00 – 21:00, T7 15/11/2026" (dùng trong email). */
    public function whenLabel(): string
    {
        return $this->timeLabel() . ', ' . str_replace(', ', ' ', $this->dateLabel());
    }
}
