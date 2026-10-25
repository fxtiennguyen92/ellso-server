<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\AppSession;
use App\Models\StudentMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Số liệu cho Thống kê › Hoạt động học viên. Chỉ tính người dùng vai trò học viên.
 *
 * - overview(): toàn thời gian / hiện tại (không theo bộ lọc).
 * - activity(): theo bộ lọc from / to / group_by — lượt mở app theo mốc, theo nền tảng,
 *   theo thứ trong tuần và theo giờ trong ngày (giờ Việt Nam). Tính một lần cho mỗi bộ lọc.
 */
class StudentActivityStatistics
{
    /** Thứ tự cố định để màu đi theo nền tảng, không theo thứ hạng. */
    public const PLATFORM_ORDER = ['ios', 'android', 'web', 'other'];

    /** @var array<string, array> */
    private static array $memo = [];

    private TimeBuckets $time;

    public function __construct(?array $filters)
    {
        $this->time = new TimeBuckets($filters);
    }

    public static function defaultFilters(): array
    {
        return TimeBuckets::defaultFilters();
    }

    public function time(): TimeBuckets
    {
        return $this->time;
    }

    /**
     * @return array{students: int, active_members: int, ever_members: int, membership_rate: float,
     *               opens: int, platforms: array<string, int>}
     */
    public static function overview(): array
    {
        $students = User::query()->where('role', UserRole::Student)->count();

        $activeMembers = StudentMembership::query()->active()
            ->whereHas('user', fn (Builder $query) => $query->where('role', UserRole::Student))
            ->distinct()->count('user_id');

        $everMembers = StudentMembership::query()
            ->whereHas('user', fn (Builder $query) => $query->where('role', UserRole::Student))
            ->distinct()->count('user_id');

        $platforms = self::emptyPlatforms();
        self::studentSessions()
            ->selectRaw('platform, COUNT(*) as total')
            ->groupBy('platform')
            ->toBase()
            ->get()
            ->each(function ($row) use (&$platforms) {
                $platforms[self::platformKey($row->platform)] += (int) $row->total;
            });

        return [
            'students' => $students,
            'active_members' => $activeMembers,
            'ever_members' => $everMembers,
            'membership_rate' => $students ? $activeMembers / $students * 100 : 0.0,
            'opens' => array_sum($platforms),
            'platforms' => $platforms,
        ];
    }

    /**
     * @return array{labels: list<string>, opens: list<int>, signups: list<int>, signups_total: int,
     *               total: int, students: int, avg_minutes: float,
     *               platforms: array<string, int>, weekdays: array<int, int>, hours: array<int, int>}
     */
    public function activity(): array
    {
        // Nhớ trong phạm vi 1 request (4 biểu đồ dùng chung một lần tính)
        $cacheKey = spl_object_id(app('request')) . ':' . md5(serialize([$this->time->from, $this->time->to, $this->time->groupBy]));

        return self::$memo[$cacheKey] ??= $this->compute();
    }

    public static function platformLabel(string $key): string
    {
        return $key === 'other' ? 'Khác' : AppSession::platformLabel($key);
    }

    /** Xoá kết quả nhớ tạm (dùng trong test). */
    public static function flush(): void
    {
        self::$memo = [];
    }

    private function compute(): array
    {
        $buckets = $this->time->buckets();
        $opens = array_fill_keys(array_keys($buckets), 0);
        $platforms = self::emptyPlatforms();
        $weekdays = array_fill(1, 7, 0);
        $hours = array_fill(0, 24, 0);
        $students = [];
        $seconds = 0;
        $total = 0;

        // Gom theo giờ VN trong PHP để chạy giống nhau trên MySQL và SQLite
        self::studentSessions()
            ->whereBetween('app_sessions.started_at', $this->time->utcRange())
            ->select(['app_sessions.id', 'app_sessions.user_id', 'app_sessions.platform', 'app_sessions.started_at', 'app_sessions.last_seen_at'])
            ->lazyById(2000, 'app_sessions.id', 'id')
            ->each(function (AppSession $s) use (&$opens, &$platforms, &$weekdays, &$hours, &$students, &$seconds, &$total) {
                $at = CarbonImmutable::instance($s->started_at)->timezone(TimeBuckets::TZ);
                $key = $this->time->key($at);

                if (! isset($opens[$key])) {
                    return;
                }

                $opens[$key]++;
                $platforms[self::platformKey($s->platform)]++;
                $weekdays[$at->isoWeekday()]++;
                $hours[$at->hour]++;
                $students[$s->user_id] = true;
                $seconds += $s->durationSeconds();
                $total++;
            });

        // Học viên mới: tài khoản học viên được tạo trong khoảng (tự đăng ký + được admin mời)
        $signups = array_fill_keys(array_keys($buckets), 0);
        User::query()
            ->where('role', UserRole::Student)
            ->whereBetween('created_at', $this->time->utcRange())
            ->select(['id', 'created_at'])
            ->lazyById(2000)
            ->each(function (User $u) use (&$signups) {
                $key = $this->time->key(CarbonImmutable::instance($u->created_at));

                if (isset($signups[$key])) {
                    $signups[$key]++;
                }
            });

        return [
            'labels' => array_values($buckets),
            'opens' => array_values($opens),
            'signups' => array_values($signups),
            'signups_total' => array_sum($signups),
            'total' => $total,
            'students' => count($students),
            'avg_minutes' => $total ? $seconds / $total / 60 : 0.0,
            'platforms' => $platforms,
            'weekdays' => $weekdays,
            'hours' => $hours,
        ];
    }

    private static function studentSessions(): Builder
    {
        return AppSession::query()
            ->join('users', 'users.id', '=', 'app_sessions.user_id')
            ->where('users.role', UserRole::Student->value);
    }

    private static function platformKey(?string $platform): string
    {
        return in_array($platform, ['ios', 'android', 'web'], true) ? $platform : 'other';
    }

    /** @return array<string, int> */
    private static function emptyPlatforms(): array
    {
        return array_fill_keys(self::PLATFORM_ORDER, 0);
    }
}
