<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Một lần học viên trả lời một câu hỏi trắc nghiệm trong bài. */
class LessonQuizAnswer extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'lesson_id', 'block_id', 'option_ids', 'is_correct'];

    protected function casts(): array
    {
        return ['option_ids' => 'array', 'is_correct' => 'boolean'];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class)->withTrashed();
    }
}
