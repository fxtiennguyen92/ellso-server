<?php

namespace App\Models;

use App\Enums\ChapterColor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PathChapter extends Model
{
    protected $fillable = ['learning_path_id', 'title', 'color', 'sort_order'];

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
}
