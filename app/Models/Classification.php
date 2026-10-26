<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tiêu chí phân loại lộ trình.
 * course_id = null → dùng chung; course_id = X → riêng của khóa X.
 */
class Classification extends Model
{
    /** Hỏi học viên trên app (chọn giá trị). */
    public const TYPE_QUESTION = 'question';

    /** Năng lực: tự đồng bộ từ phân loại của bài kiểm tra đầu vào, giá trị = mức độ, lấy từ kết quả bài làm. */
    public const TYPE_ABILITY = 'ability';

    protected $fillable = ['course_id', 'type', 'placement_dimension_key', 'name', 'question', 'description', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        // Phân loại riêng tạo xong → tự gắn vào khóa của nó
        static::saved(function (Classification $classification) {
            if ($classification->course_id && ($classification->wasRecentlyCreated || $classification->wasChanged('course_id'))) {
                $course = $classification->course;
                $course->classifications()->syncWithoutDetaching([
                    $classification->id => ['sort_order' => (int) $course->courseClassifications()->max('sort_order') + 1],
                ]);
            }
        });
    }

    public function isAbility(): bool
    {
        return $this->type === self::TYPE_ABILITY;
    }

    /** Tên hiển thị trong ô chọn: "Năng lực · Khả năng tiếp thu", "Mục tiêu học (riêng)". */
    public function displayName(): string
    {
        if ($this->isAbility()) {
            return 'Năng lực · ' . $this->name;
        }

        return $this->name . ($this->isShared() ? '' : ' (riêng)');
    }

    public function isShared(): bool
    {
        return $this->course_id === null;
    }

    public function scopeShared(Builder $query): void
    {
        $query->whereNull('course_id');
    }

    /** Phân loại khóa X được dùng: dùng chung + riêng của X */
    public function scopeAvailableFor(Builder $query, ?int $courseId): void
    {
        $query->where(fn ($q) => $q->whereNull('course_id')->when($courseId, fn ($q) => $q->orWhere('course_id', $courseId)));
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(ClassificationOption::class)->orderBy('sort_order');
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_classifications')->withPivot('sort_order')->withTimestamps();
    }
}
