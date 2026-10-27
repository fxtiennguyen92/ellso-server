<?php

namespace App\Support;

use App\Models\Classification;
use App\Models\ClassificationOption;
use App\Models\PlacementAttempt;
use App\Models\PlacementDimension;
use App\Models\PlacementLevel;
use App\Models\PlacementTest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Phân loại "Năng lực" = phân loại của bài kiểm tra đầu vào, dùng làm điều kiện lộ trình.
 *
 * - Mỗi phân loại của bài kiểm tra (theo key ổn định) ↔ một Classification type=ability, dùng chung.
 *   Giá trị (ClassificationOption) ↔ các mức độ (theo key), thứ tự từ mức cao xuống thấp.
 * - Tên / mức lấy từ bài đang dùng; phân loại không có trong bài đang dùng → "không dùng" (is_active = false),
 *   mức đã xoá → giá trị ẩn. Không xoá để giữ điều kiện lộ trình cũ.
 * - Tự đồng bộ khi sửa bài kiểm tra (xem booted() của các model Placement*).
 * - Giá trị năng lực của học viên = mức trong bài làm mới nhất.
 */
class AbilityClassifications
{
    private static bool $paused = false;

    /** Chạy $callback không đồng bộ từng bước, đồng bộ một lần ở cuối (VD: nhân bản cả bài). */
    public static function batch(callable $callback): mixed
    {
        if (self::$paused) {
            return $callback();
        }

        self::$paused = true;
        try {
            $result = $callback();
        } finally {
            self::$paused = false;
        }
        self::sync();

        return $result;
    }

    public static function syncSoon(): void
    {
        if (! self::$paused) {
            self::sync();
        }
    }

    public static function sync(): void
    {
        DB::transaction(function () {
            $activeId = PlacementTest::current()?->id;

            // Nhóm theo key; đại diện = bản trong bài đang dùng, không có thì bài mới nhất
            $groups = PlacementDimension::query()
                ->whereNotNull('key')
                ->with('levels')
                ->orderByDesc('placement_test_id')
                ->get()
                ->groupBy('key');

            foreach ($groups as $key => $dimensions) {
                /** @var PlacementDimension $dimension */
                $dimension = $dimensions->firstWhere('placement_test_id', $activeId) ?? $dimensions->first();

                $classification = Classification::query()->firstOrNew([
                    'type' => Classification::TYPE_ABILITY,
                    'placement_dimension_key' => $key,
                ]);
                $classification->fill([
                    'course_id' => null,
                    'name' => $dimension->name,
                    'question' => null,
                    'description' => $dimension->description,
                    'is_active' => $activeId !== null && $dimension->placement_test_id === $activeId,
                ]);
                if (! $classification->exists) {
                    $classification->sort_order = (int) Classification::query()->max('sort_order') + 1;
                }
                $classification->save();

                $levels = $dimension->levels->sortByDesc('min_score')->values();
                foreach ($levels as $i => $level) {
                    $option = ClassificationOption::query()->firstOrNew([
                        'classification_id' => $classification->id,
                        'placement_level_key' => $level->key,
                    ]);
                    $option->fill(['name' => $level->name, 'description' => $level->description, 'sort_order' => $i + 1, 'is_active' => true])->save();
                }

                ClassificationOption::query()
                    ->where('classification_id', $classification->id)
                    ->whereNotIn('placement_level_key', $levels->pluck('key')->filter()->all())
                    ->update(['is_active' => false]);
            }

            // Phân loại năng lực không còn trong bài kiểm tra nào
            Classification::query()
                ->where('type', Classification::TYPE_ABILITY)
                ->whereNotIn('placement_dimension_key', $groups->keys()->all())
                ->update(['is_active' => false]);
        });
    }

    /**
     * Giá trị năng lực của học viên (id ClassificationOption) theo bài làm mới nhất.
     *
     * @return list<int>
     */
    public static function optionIdsFor(User $user): array
    {
        $attempt = PlacementAttempt::query()
            ->where('user_id', $user->id)
            ->latest('completed_at')
            ->latest('id')
            ->first();

        if (! $attempt) {
            return [];
        }

        $results = collect($attempt->results ?? []);
        $keys = $results->pluck('level_key')->filter();

        // Bài làm trước khi có key: tra key theo level_id
        $missing = $results->whereNull('level_key')->pluck('level_id')->filter();
        if ($missing->isNotEmpty()) {
            $keys = $keys->merge(PlacementLevel::query()->whereKey($missing->all())->pluck('key'));
        }

        if ($keys->isEmpty()) {
            return [];
        }

        return ClassificationOption::query()
            ->whereIn('placement_level_key', $keys->unique()->all())
            ->whereHas('classification', fn ($query) => $query->where('type', Classification::TYPE_ABILITY))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** Bài kiểm tra chứa phân loại năng lực này (bài đang dùng, không thì bài mới nhất) — để mở trang cấu hình. */
    public static function testFor(Classification $classification): ?PlacementTest
    {
        return PlacementDimension::query()
            ->where('key', $classification->placement_dimension_key)
            ->with('test')
            ->get()
            ->sortByDesc(fn (PlacementDimension $d) => [$d->test?->is_active ? 1 : 0, $d->placement_test_id])
            ->first()?->test;
    }
}
