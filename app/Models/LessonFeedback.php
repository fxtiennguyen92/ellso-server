<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Đánh giá cuối bài của học viên: 1 = hữu ích, -1 = chưa hữu ích (+ lý do). */
class LessonFeedback extends Model
{
    protected $table = 'lesson_feedback';

    /** Lý do khi "chưa hữu ích" (mã → nhãn hiển thị trên app & CMS). */
    public const REASONS = [
        'hard' => 'Khó hiểu',
        'long' => 'Quá dài',
        'easy' => 'Quá dễ',
        'error' => 'Nội dung có lỗi',
        'media' => 'Video / ảnh có vấn đề',
    ];

    protected $fillable = ['user_id', 'lesson_id', 'rating', 'reasons', 'comment'];

    protected function casts(): array
    {
        return ['rating' => 'integer', 'reasons' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
