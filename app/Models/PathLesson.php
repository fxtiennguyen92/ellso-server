<?php

namespace App\Models;

use App\Enums\UnlockMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vị trí của một bài (trong thư viện) ở một chương của lộ trình:
 * thứ tự, quy tắc mở khóa, học thử.
 */
class PathLesson extends Model
{
    protected $fillable = ['learning_path_id', 'path_chapter_id', 'lesson_id', 'unlock_mode', 'is_preview', 'sort_order'];

    protected function casts(): array
    {
        return [
            'unlock_mode' => UnlockMode::class,
            'is_preview' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // learning_path_id luôn lấy theo chương
        static::saving(function (PathLesson $item) {
            if ($item->path_chapter_id && $item->isDirty('path_chapter_id')) {
                $item->learning_path_id = PathChapter::query()
                    ->whereKey($item->path_chapter_id)
                    ->value('learning_path_id');
            }

            $item->unlock_mode ??= UnlockMode::AfterPrevious;
        });
    }

    public function learningPath(): BelongsTo
    {
        return $this->belongsTo(LearningPath::class);
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(PathChapter::class, 'path_chapter_id');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
