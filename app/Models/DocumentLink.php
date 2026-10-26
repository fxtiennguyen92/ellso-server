<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Điều kiện "theo khoá học" (course_id) hoặc "theo bài học" (lesson_id) của tài liệu. */
class DocumentLink extends Model
{
    public $timestamps = false;

    protected $fillable = ['document_id', 'course_id', 'lesson_id'];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class)->withTrashed();
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class)->withTrashed();
    }
}
