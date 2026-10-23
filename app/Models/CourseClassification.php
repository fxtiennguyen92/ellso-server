<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Khóa học dùng phân loại nào, theo thứ tự (= thứ tự câu hỏi trên app). */
class CourseClassification extends Model
{
    protected $fillable = ['course_id', 'classification_id', 'sort_order'];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function classification(): BelongsTo
    {
        return $this->belongsTo(Classification::class);
    }
}
