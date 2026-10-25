<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\PlacementAttempt;
use App\Models\PlacementTest;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Số liệu cho Thống kê › Kiểm tra đầu vào, theo bộ lọc: test_id, from, to, group_by (giờ VN).
 * Phân bố mức độ: mỗi học viên tính bài làm MỚI NHẤT trong khoảng (làm lại không bị đếm trùng).
 */
class PlacementStatistics
{
    private TimeBuckets $time;

    private ?PlacementTest $test;

    /** @var array<string, mixed> */
    private static array $memo = [];

    public function __construct(?array $filters)
    {
        $this->time = new TimeBuckets($filters);
        $this->test = self::resolveTest($filters['test_id'] ?? null);
    }

    public static function defaultFilters(): array
    {
        return ['test_id' => self::resolveTest(null)?->id, ...TimeBuckets::defaultFilters()];
    }

    /** Bài được chọn → bài đang dùng → bài mới nhất. */
    public static function resolveTest(mixed $id): ?PlacementTest
    {
        return ($id ? PlacementTest::find($id) : null)
            ?? PlacementTest::current()
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
                ->where('placement_test_id', $this->test->id)
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
                ? PlacementAttempt::query()->where('placement_test_id', $this->test->id)->whereIn('user_id', $signupIds)->distinct()->count('user_id')
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
     * Phân bố mức độ của một phân loại: [{name, count, rank}] từ mức cao → thấp.
     * Mức theo cấu hình hiện tại; mức đã xoá vẫn hiện theo tên trong bài làm.
     */
    public function distribution(int $dimensionId): array
    {
        return $this->memo('dist.' . $dimensionId, function () use ($dimensionId) {
            $dimension = $this->test?->dimensions()->with('levels')->find($dimensionId);
            if (! $dimension) {
                return [];
            }

            $rows = $dimension->levels->sortByDesc('min_score')->values()
                ->mapWithKeys(fn ($level, $i) => ['id:' . $level->id => ['name' => $level->name, 'count' => 0, 'rank' => $i + 1]])
                ->all();

            $latest = $this->attempts()->groupBy('user_id')->map->last();
            foreach ($latest as $attempt) {
                $result = collect($attempt->results)->firstWhere('dimension_id', $dimensionId);
                if (! $result) {
                    continue;
                }
                $key = isset($rows['id:' . $result['level_id']]) ? 'id:' . $result['level_id'] : 'name:' . ($result['level'] ?? '—');
                $rows[$key] ??= ['name' => ($result['level'] ?? '—') . ' (mức cũ)', 'count' => 0, 'rank' => count($rows) + 1];
                $rows[$key]['count']++;
            }

            return array_values($rows);
        });
    }

    private function memo(string $key, callable $compute): mixed
    {
        $full = md5(serialize([$this->test?->id, $this->time->from, $this->time->to, $this->time->groupBy])) . '.' . $key;

        return self::$memo[$full] ??= $compute();
    }
}
