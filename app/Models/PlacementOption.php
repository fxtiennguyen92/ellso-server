<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Đáp án (hoặc mục cần sắp xếp). scores = {dimension_id: điểm cộng/trừ}. */
class PlacementOption extends Model
{
    protected $fillable = ['placement_question_id', 'label', 'scores', 'sort_order'];

    protected function casts(): array
    {
        return ['scores' => 'array'];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(PlacementQuestion::class, 'placement_question_id');
    }

    public function scoreFor(int $dimensionId): int
    {
        return (int) ($this->scores[$dimensionId] ?? $this->scores[(string) $dimensionId] ?? 0);
    }
}
