<?php

namespace App\Models;

use App\Enums\PlacementQuestionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlacementQuestion extends Model
{
    protected $fillable = [
        'placement_test_id',
        'type',
        'prompt',
        'hint',
        'min_choices',
        'max_choices',
        'position_weights',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'type' => PlacementQuestionType::class,
            'position_weights' => 'array',
            'min_choices' => 'integer',
            'max_choices' => 'integer',
        ];
    }

    public function test(): BelongsTo
    {
        return $this->belongsTo(PlacementTest::class, 'placement_test_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(PlacementOption::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Trọng số mặc định cho câu sắp xếp n mục: hạng 1 = n−1, …, hạng cuối = 0. */
    public static function defaultWeights(int $count): array
    {
        return $count > 0 ? range($count - 1, 0) : [];
    }

    /** Trọng số đủ độ dài bằng số mục (thiếu → bù theo mặc định, thừa → cắt). */
    public function weights(?int $count = null): array
    {
        $count ??= $this->options->count();
        $weights = array_values($this->position_weights ?? []);
        $default = self::defaultWeights($count);

        if ($count === 0) {
            return [];
        }

        // Ô trống → trọng số mặc định của vị trí đó
        return array_map(
            fn ($i) => isset($weights[$i]) && $weights[$i] !== '' ? (int) $weights[$i] : $default[$i],
            range(0, $count - 1),
        );
    }

    /** Số lựa chọn tối thiểu / tối đa của câu chọn nhiều. */
    public function choiceBounds(): array
    {
        $count = $this->options->count();
        $min = max(1, min($this->min_choices ?? 1, $count));
        $max = max($min, min($this->max_choices ?? $count, $count));

        return [$min, $max];
    }
}
