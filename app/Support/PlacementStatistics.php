<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\PlacementAttempt;
use App\Models\PlacementTest;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Số liệu cho Thống kê › Kiểm tra đầu vào, theo bộ lọc: test_key, from, to, group_by (giờ VN).
 * - Lọc theo BÀI (key): gộp bài làm của mọi phiên bản (bản nháp đã thay bản cũ vẫn tính chung).
 * - Phân bố mức độ: theo key năng lực / mức (ổn định giữa các phiên bản); mỗi học viên tính bài làm MỚI NHẤT
 *   trong khoảng (làm lại không bị đếm trùng). Tên mức theo phiên bản đang dùng.
 */
class PlacementStatistics
{
    private TimeBuckets $time;

    /** Phiên bản đại diện của bài: đang dùng, không thì mới nhất. */
    private ?PlacementTest $test;

    /** @var list<int> id mọi phiên bản của bài */
    private array $versionIds = [];

    /** @var array<string, mixed> */
    private static array $memo = [];

    public function __construct(?array $filters)
    {
        $this->time = new TimeBuckets($filters);
        $this->test = self::resolveTest($filters['test_key'] ?? null);
        $this->versionIds = $this->test
            ? PlacementTest::query()->where('key', $this->test->key)->pluck('id')->map(fn ($id) => (int) $id)->all()
            : [];
    }

    public static function defaultFilters(): array
    {
        return ['test_key' => self::resolveTest(null)?->key, ...TimeBuckets::defaultFilters()];
    }

    /** Bài được chọn (key) → bài đang dùng đầu tiên → bài mới nhất. Trả phiên bản đang dùng (hoặc mới nhất) của bài. */
    public static function resolveTest(mixed $key): ?PlacementTest
    {
        $test = $key ? PlacementTest::query()->where('key', $key)->orderByDesc('is_active')->latest('id')->first() : null;

        return $test
            ?? PlacementTest::query()->active()->ordered()->first()
            ?? PlacementTest::latest('id')->first();
    }

    public function time(): TimeBuckets
    {
        return $this->time;
    }

    public function test(): ?PlacementTest
    {
        return $this->test;
    }

    /** Bài làm của học viên trong khoảng lọc. */
    private function attempts(): Collection
    {
        return $this->memo('attempts', function () {
            if (! $this->test) {
                return collect();
            }

            return PlacementAttempt::query()
                ->whereIn('placement_test_id', $this->versionIds)
                ->whereBetween('completed_at', $this->time->utcRange())
                ->whereHas('user', fn ($query) => $query->where('role', UserRole::Student))
                ->orderBy('completed_at')
                ->get(['id', 'user_id', 'results', 'completed_at']);
        });
    }

    /**
     * @return array{attempts:int, students:int, retakes:int, signups:int, signups_done:int, completion_rate:float}
     */
    public function summary(): array
    {
        return $this->memo('summary', function () {
            $attempts = $this->attempts();
            $students = $attempts->pluck('user_id')->unique()->count();

            // Học viên đăng ký mới trong khoảng → bao nhiêu % đã làm bài này (tính đến nay)
            $signupIds = User::query()
                ->where('role', UserRole::Student)
                ->whereBetween('created_at', $this->time->utcRange())
                ->pluck('id');
            $done = $this->test && $signupIds->isNotEmpty()
                ? PlacementAttempt::query()->whereIn('placement_test_id', $this->versionIds)->whereIn('user_id', $signupIds)->distinct()->count('user_id')
                : 0;

            return [
                'attempts' => $attempts->count(),
                'students' => $students,
                'retakes' => $attempts->count() - $students,
                'signups' => $signupIds->count(),
                'signups_done' => $done,
                'completion_rate' => $signupIds->count() ? $done / $signupIds->count() * 100 : 0.0,
            ];
        });
    }

    /** @return array{labels: list<string>, attempts: list<int>} */
    public function trend(): array
    {
        return $this->memo('trend', function () {
            $buckets = $this->time->buckets();
            $counts = array_fill_keys(array_keys($buckets), 0);

            foreach ($this->attempts() as $attempt) {
                $key = $this->time->key($attempt->completed_at);
                if (isset($counts[$key])) {
                    $counts[$key]++;
                }
            }

            return ['labels' => array_values($buckets), 'attempts' => array_values($counts)];
        });
    }

    /**
     * Phân bố mức độ của một năng lực (theo key): [{name, count, rank}] từ mức cao → thấp.
     * Mức theo cấu hình phiên bản đang dùng; mức không còn hiện theo tên trong bài làm ("mức cũ");
     * bộ câu không tính điểm năng lực này → "Chưa xác định".
     */
    public function distribution(string $dimensionKey): array
    {
        return $this->memo('dist.' . $dimensionKey, fn () => $this->levelStats($dimensionKey)['rows']);
    }

    /** % điểm trung bình của năng lực (bài làm mới nhất mỗi học viên), null nếu chưa có. */
    public function averagePercent(string $dimensionKey): ?float
    {
        return $this->memo('avg.' . $dimensionKey, fn () => $this->levelStats($dimensionKey)['average']);
    }

    /** @return array{rows: list<array{name:string,count:int,rank:int}>, average: ?float} */
    private function levelStats(string $dimensionKey): array
    {
        return $this->memo('stats.' . $dimensionKey, function () use ($dimensionKey) {
            $dimension = $this->test?->dimensions()->with('levels')->where('key', $dimensionKey)->first();
            if (! $dimension) {
                return ['rows' => [], 'average' => null];
            }

            $levels = $dimension->levels->sortByDesc('min_percent')->values();
            $rows = $levels->mapWithKeys(fn ($level, $i) => ['key:' . $level->key => ['name' => $level->name, 'count' => 0, 'rank' => $i + 1]])->all();
            $byId = $levels->mapWithKeys(fn ($level) => [$level->id => 'key:' . $level->key])->all();

            $percents = [];
            $latest = $this->attempts()->groupBy('user_id')->map->last();
            foreach ($latest as $attempt) {
                $result = collect($attempt->results)->first(fn ($r) => ($r['dimension_key'] ?? null) === $dimensionKey
                    || (! isset($r['dimension_key']) && ($r['dimension_id'] ?? null) === $dimension->id));
                if (! $result) {
                    continue;
                }

                $percent = $result['percent'] ?? (($result['max'] ?? 0) > ($result['min'] ?? 0)
                    ? ($result['score'] - $result['min']) / ($result['max'] - $result['min']) * 100
                    : null);
                if ($percent !== null) {
                    $percents[] = (float) $percent;
                }

                if (($result['level'] ?? null) === null) {
                    $key = 'none';
                    $rows[$key] ??= ['name' => 'Chưa xác định', 'count' => 0, 'rank' => 99];
                } else {
                    $key = isset($result['level_key'], $rows['key:' . $result['level_key']])
                        ? 'key:' . $result['level_key']
                        : ($byId[$result['level_id'] ?? 0] ?? 'name:' . $result['level']);
                    $rows[$key] ??= ['name' => $result['level'] . ' (mức cũ)', 'count' => 0, 'rank' => count($rows) + 1];
                }
                $rows[$key]['count']++;
            }

            return [
                'rows' => array_values($rows),
                'average' => $percents ? round(array_sum($percents) / count($percents), 1) : null,
            ];
        });
    }

    private function memo(string $key, callable $compute): mixed
    {
        $full = md5(serialize([$this->test?->key, $this->time->from, $this->time->to, $this->time->groupBy])) . '.' . $key;

        return self::$memo[$full] ??= $compute();
    }
}
