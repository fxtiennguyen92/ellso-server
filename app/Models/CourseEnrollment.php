<?php

namespace App\Models;

use App\Enums\EnrollmentSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Học viên được học một khoá. learning_path_id = null → chưa chọn lộ trình
 * (app hỏi câu hỏi phân loại rồi gợi ý, hoặc admin gán). Tiến độ: App\Services\LearningProgress.
 */
class CourseEnrollment extends Model
{
    protected $fillable = [
        'user_id', 'course_id', 'learning_path_id', 'source', 'granted_by_id', 'note',
        'enrolled_at', 'last_activity_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'source' => EnrollmentSource::class,
            'enrolled_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class)->withTrashed();
    }

    public function learningPath(): BelongsTo
    {
        return $this->belongsTo(LearningPath::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_id');
    }
}
