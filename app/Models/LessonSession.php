<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Một lần học viên mở bài học trên app (xem Api\LessonTrackingController). */
class LessonSession extends Model
{
    protected $fillable = [
        'user_id', 'lesson_id', 'course_id', 'platform', 'started_at', 'last_seen_at',
        'active_seconds', 'max_scroll_percent', 'reached_end', 'video_seconds', 'completed',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'active_seconds' => 'integer',
            'max_scroll_percent' => 'integer',
            'reached_end' => 'boolean',
            'video_seconds' => 'integer',
            'completed' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
