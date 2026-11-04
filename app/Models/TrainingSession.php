<?php

namespace App\Models;

use App\Models\Concerns\ScheduledSession;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một buổi của chương trình huấn luyện: ngày giờ, địa điểm (sổ địa chỉ), ghi chú.
 * Số thứ tự "Buổi n" tính theo ngày trong các buổi chưa huỷ: TrainingCourse::sessionNumbers().
 */
class TrainingSession extends Model
{
    use ScheduledSession;

    public const TZ = 'Asia/Ho_Chi_Minh';

    protected $fillable = ['training_course_id', 'training_location_id', 'starts_at', 'ends_at', 'note', 'cancelled_at'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
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
}
