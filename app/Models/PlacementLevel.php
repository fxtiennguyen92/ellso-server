<?php

namespace App\Models;

use App\Support\AbilityClassifications;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * Mức độ của một năng lực: đạt khi % điểm ≥ min_percent (0–100, lấy mức cao nhất thoả).
 * % = vị trí của tổng điểm trong khoảng điểm có thể đạt với các câu học viên nhận được (xem PlacementScoring).
 */
class PlacementLevel extends Model
{
    protected $fillable = ['key', 'placement_dimension_id', 'name', 'min_percent', 'description', 'sort_order'];

    protected static function booted(): void
    {
        // Mã ổn định (giữ khi nhân bản bài) để nối với phân loại "Năng lực" của lộ trình
        static::creating(function (self $model) {
            $model->key ??= (string) Str::uuid();
        });
        static::saved(fn () => AbilityClassifications::syncSoon());
        static::deleted(fn () => AbilityClassifications::syncSoon());
    }

    protected function casts(): array
    {
        return ['min_percent' => 'integer'];
    }

    public function dimension(): BelongsTo
    {
        return $this->belongsTo(PlacementDimension::class, 'placement_dimension_id');
    }

    /** Khoá học gợi ý khi đạt mức này. */
    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'placement_level_courses');
    }
}
