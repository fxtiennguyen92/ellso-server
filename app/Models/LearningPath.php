<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LearningPath extends Model
{
    protected $fillable = [
        'course_id',
        'name',
        'description',
        'is_default',
        'sort_order',
    ];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    protected static function booted(): void
    {
        // Mỗi khóa chỉ có 1 lộ trình mặc định
        static::saved(function (LearningPath $path) {
            if ($path->is_default) {
                static::query()
                    ->where('course_id', $path->course_id)
                    ->whereKeyNot($path->getKey())
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }
        });
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** Điều kiện áp dụng: các giá trị phân loại. Phân loại nào không có giá trị = mọi giá trị. */
    public function options(): BelongsToMany
    {
        return $this->belongsToMany(ClassificationOption::class, 'learning_path_options');
    }

    public function chapters(): HasMany
    {
        return $this->hasMany(PathChapter::class)->orderBy('sort_order');
    }

    public function pathLessons(): HasMany
    {
        return $this->hasMany(PathLesson::class);
    }

    /**
     * Nhãn điều kiện để hiển thị, VD: ["Nghề nghiệp: Marketing, IT", "Mục tiêu học: Hiểu"].
     *
     * @return array<string>
     */
    public function conditionLabels(): array
    {
        return $this->options
            ->loadMissing('classification')
            ->groupBy('classification_id')
            ->map(fn ($options) => $options->first()->classification->name . ': ' . $options->pluck('name')->join(', '))
            ->values()
            ->all();
    }

    /**
     * Điều kiện dạng [classification_id => [option_id, …]] — dùng để điền form CMS.
     *
     * @return array<int, array<int>>
     */
    public function conditionsByClassification(): array
    {
        return $this->options
            ->groupBy('classification_id')
            ->map(fn ($options) => $options->pluck('id')->map(fn ($id) => (string) $id)->all())
            ->all();
    }
}
