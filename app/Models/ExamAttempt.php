<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một lần làm bài thi. questions = bộ câu đã giao (cố định cho lần làm này); review = bản chụp chấm điểm.
 * Chưa nộp: submitted_at null. Hết giờ mà chưa nộp → chấm với câu trả lời đã có (không có = sai).
 */
class ExamAttempt extends Model
{
    /** Cho phép nộp trễ vài giây (mạng chậm). */
    public const GRACE_SECONDS = 60;

    protected $fillable = [
        'user_id', 'exam_id', 'path_chapter_id', 'course_id', 'questions', 'answers', 'review',
        'correct_count', 'total', 'score', 'passed', 'started_at', 'expires_at', 'submitted_at', 'timed_out',
    ];

    protected function casts(): array
    {
        return [
            'questions' => 'array',
            'answers' => 'array',
            'review' => 'array',
            'score' => 'float',
            'passed' => 'boolean',
            'timed_out' => 'boolean',
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function pathChapter(): BelongsTo
    {
        return $this->belongsTo(PathChapter::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function isOpen(): bool
    {
        return $this->submitted_at === null;
    }

    /** Đã quá giờ (kể cả thời gian ân hạn) mà chưa nộp. */
    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->expires_at !== null && now()->gt($this->expires_at->copy()->addSeconds(self::GRACE_SECONDS));
    }
}
