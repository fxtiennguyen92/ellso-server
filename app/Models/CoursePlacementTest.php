<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Khoá học bắt buộc làm bài kiểm tra đầu vào nào (theo key bài — mọi phiên bản). */
class CoursePlacementTest extends Model
{
    public $timestamps = false;

    protected $fillable = ['course_id', 'placement_test_key', 'sort_order'];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
