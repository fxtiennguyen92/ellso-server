<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Một ngày tổ chức của khoá huấn luyện: thời gian, địa điểm, số người tối đa. */
class TrainingSession extends Model
{
    public const TZ = 'Asia/Ho_Chi_Minh';

    protected $fillable = ['training_course_id', 'training_location_id', 'starts_at', 'ends_at', 'capacity', 'note', 'cancelled_at'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'capacity' => 'integer',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(TrainingCourse::class, 'training_course_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(TrainingLocation::class, 'training_location_id')->withTrashed();
    }

    /** Chưa bắt đầu và chưa huỷ. */
    public function scopeUpcoming(Builder $query): void
    {
        $query->whereNull('cancelled_at')->where('starts_at', '>', now());
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
}
