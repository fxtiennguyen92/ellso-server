<?php

namespace App\Models;

use App\Enums\ChapterColor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Mục ngang cấp trong lộ trình: chương (kind = chapter, chứa bài) hoặc bài thi (kind = exam, trỏ tới đề trong
 * ngân hàng đề). Chương và bài thi dùng chung thứ tự kéo thả (sort_order).
 */
class PathChapter extends Model
{
    public const KIND_CHAPTER = 'chapter';

    public const KIND_EXAM = 'exam';

    protected $fillable = ['learning_path_id', 'kind', 'exam_id', 'title', 'color', 'sort_order'];

    protected $attributes = ['kind' => self::KIND_CHAPTER];

    protected function casts(): array
    {
        return ['color' => ChapterColor::class];
    }

    protected static function booted(): void
    {
        // Chương mới: tự lấy màu kế tiếp + xếp cuối
        static::creating(function (PathChapter $chapter) {
            $count = static::where('learning_path_id', $chapter->learning_path_id)->count();
            $chapter->color ??= ChapterColor::forIndex($count);
            $chapter->sort_order = $chapter->sort_order ?: $count + 1;
        });
    }

    public function learningPath(): BelongsTo
    {
        return $this->belongsTo(LearningPath::class);
    }

    public function pathLessons(): HasMany
    {
        return $this->hasMany(PathLesson::class)->orderBy('sort_order');
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function isExam(): bool
    {
        return $this->kind === self::KIND_EXAM;
    }
}
