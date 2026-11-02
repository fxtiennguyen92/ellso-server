<?php

namespace App\Support;

use App\Models\Classification;
use App\Models\ClassificationOption;
use App\Models\PlacementAttempt;
use App\Models\PlacementDimension;
use App\Models\PlacementLevel;
use App\Models\PlacementTest;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phân loại "Năng lực" = phân loại của bài kiểm tra đầu vào, dùng làm điều kiện lộ trình.
 *
 * - Mỗi phân loại của bài kiểm tra (theo key ổn định) ↔ một Classification type=ability, dùng chung.
 *   Giá trị (ClassificationOption) ↔ các mức độ (theo key), thứ tự từ mức cao xuống thấp.
 * - Có thể có nhiều bài đang dùng (mỗi bài một nhóm năng lực). Tên / mức lấy từ phiên bản đang dùng;
 *   phân loại không có trong bài nào đang dùng → "không dùng" (is_active = false), mức đã xoá → giá trị ẩn.
 *   Không xoá để giữ điều kiện lộ trình cũ.
 * - Tự đồng bộ khi sửa bài kiểm tra (xem booted() của các model Placement*).
 * - Giá trị năng lực của học viên = mức trong bài làm mới nhất của TỪNG bài (theo key bài), gộp lại.
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
            $activeIds = PlacementTest::query()->active()->pluck('id')->map(fn ($id) => (int) $id)->all();

            // Nhóm theo key; đại diện = bản trong bài đang dùng, không có thì bài mới nhất
            $groups = PlacementDimension::query()
                ->whereNotNull('key')
                ->with('levels')
                ->orderByDesc('placement_test_id')
                ->get()
                ->groupBy('key');

            foreach ($groups as $key => $dimensions) {
                /** @var PlacementDimension $dimension */
                $dimension = $dimensions->first(fn (PlacementDimension $d) => in_array((int) $d->placement_test_id, $activeIds, true))
                    ?? $dimensions->first();

                $classification = Classification::query()->firstOrNew([
                    'type' => Classification::TYPE_ABILITY,
                    'placement_dimension_key' => $key,
                ]);
                $classification->fill([
                    'course_id' => null,
                    'name' => $dimension->name,
                    'question' => null,
                    'description' => $dimension->description,
                    'is_active' => in_array((int) $dimension->placement_test_id, $activeIds, true),
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
     * Bài làm mới nhất của học viên cho từng bài kiểm tra (theo key bài — mọi phiên bản tính chung).
     *
     * @return Collection<string, PlacementAttempt> key bài => bài làm
     */
    public static function latestAttemptsFor(User $user): Collection
    {
        return PlacementAttempt::query()
            ->where('user_id', $user->id)
            ->with('test')
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (PlacementAttempt $a) => $a->test !== null)
            ->unique(fn (PlacementAttempt $a) => $a->test->key)
            ->keyBy(fn (PlacementAttempt $a) => $a->test->key);
    }

    /**
     * Giá trị năng lực của học viên (id ClassificationOption): mức trong bài làm mới nhất của từng bài.
     *
     * @return list<int>
     */
    public static function optionIdsFor(User $user): array
    {
        $attempts = self::latestAttemptsFor($user);

        if ($attempts->isEmpty()) {
            return [];
        }

        $results = $attempts->flatMap(fn (PlacementAttempt $a) => $a->results ?? []);
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
