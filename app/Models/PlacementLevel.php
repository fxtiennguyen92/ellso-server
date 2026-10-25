<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Mức độ của một phân loại: đạt khi tổng điểm ≥ min_score (lấy mức cao nhất thoả). */
class PlacementLevel extends Model
{
    protected $fillable = ['placement_dimension_id', 'name', 'min_score', 'description', 'sort_order'];

    protected function casts(): array
    {
        return ['min_score' => 'integer'];
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
