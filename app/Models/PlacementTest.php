<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Bài kiểm tra đầu vào. Chỉ một bài đang dùng (is_active) — app luôn lấy bài này.
 * Cách chấm điểm: xem App\Services\PlacementScoring.
 */
class PlacementTest extends Model
{
    protected $fillable = ['title', 'intro', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        // Bật bài này → tắt các bài khác
        static::saved(function (PlacementTest $test) {
            if ($test->is_active) {
                static::query()->whereKeyNot($test->getKey())->where('is_active', true)->update(['is_active' => false]);
            }
        });
    }

    public static function current(): ?self
    {
        return static::query()->where('is_active', true)->first();
    }

    public function dimensions(): HasMany
    {
        return $this->hasMany(PlacementDimension::class)->orderBy('sort_order')->orderBy('id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(PlacementQuestion::class)->orderBy('sort_order')->orderBy('id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PlacementAttempt::class);
    }

    /**
     * Nhân bản toàn bộ đề (phân loại, mức độ, khoá gợi ý, câu hỏi, đáp án + điểm) thành bài mới chưa dùng.
     * Dùng khi muốn sửa đề mà không làm lẫn thống kê của bài đang chạy.
     */
    public function duplicate(): self
    {
        $this->loadStructure();

        return DB::transaction(function () {
            $copy = static::create([
                'title' => $this->title . ' (bản sao)',
                'intro' => $this->intro,
                'is_active' => false,
            ]);

            $dimensionMap = [];
            foreach ($this->dimensions as $dimension) {
                $newDimension = $copy->dimensions()->create($dimension->only(['name', 'description', 'sort_order']));
                $dimensionMap[$dimension->id] = $newDimension->id;

                foreach ($dimension->levels as $level) {
                    $newLevel = $newDimension->levels()->create($level->only(['name', 'min_score', 'description', 'sort_order']));
                    $newLevel->courses()->sync($level->courses->pluck('id'));
                }
            }

            foreach ($this->questions as $question) {
                $newQuestion = $copy->questions()->create($question->only([
                    'type', 'prompt', 'hint', 'min_choices', 'max_choices', 'position_weights', 'sort_order',
                ]));

                foreach ($question->options as $option) {
                    $scores = [];
                    foreach ($option->scores ?? [] as $dimensionId => $points) {
                        if (isset($dimensionMap[$dimensionId])) {
                            $scores[$dimensionMap[$dimensionId]] = $points;
                        }
                    }
                    $newQuestion->options()->create([
                        'label' => $option->label,
                        'scores' => $scores,
                        'sort_order' => $option->sort_order,
                    ]);
                }
            }

            return $copy;
        });
    }

    /** Nạp đủ đề + cách chấm để chấm điểm / hiển thị. */
    public function loadStructure(): static
    {
        return $this->load(['questions.options', 'dimensions.levels.courses']);
    }
}
