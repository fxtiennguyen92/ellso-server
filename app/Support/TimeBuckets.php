<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;

/**
 * Khoảng thời gian + cách gom mốc (ngày / tuần / tháng) cho các màn Thống kê.
 * Mọi mốc tính theo giờ Việt Nam; tuần bắt đầu thứ Hai.
 *
 * Bộ lọc: from / to (Y-m-d), group_by (day|week|month).
 */
class TimeBuckets
{
    public const TZ = 'Asia/Ho_Chi_Minh';

    public const DAY = 'day';

    public const WEEK = 'week';

    public const MONTH = 'month';

    /** Số mốc tối đa trên trục ngang. */
    public const MAX_BUCKETS = 400;

    public readonly CarbonImmutable $from;

    public readonly CarbonImmutable $to;

    public readonly string $groupBy;

    public function __construct(?array $filters)
    {
        $defaults = self::defaultFilters();
        $filters ??= $defaults;

        $from = CarbonImmutable::parse($filters['from'] ?? $defaults['from'], self::TZ)->startOfDay();
        $to = CarbonImmutable::parse($filters['to'] ?? $defaults['to'], self::TZ)->endOfDay();

        if ($to->lt($from)) {
            [$from, $to] = [$to->startOfDay(), $from->endOfDay()];
        }

        $this->from = $from;
        $this->to = $to;
        $this->groupBy = in_array($filters['group_by'] ?? null, [self::DAY, self::WEEK, self::MONTH], true)
            ? $filters['group_by']
            : self::DAY;
    }

    /** 30 ngày gần nhất, theo ngày. */
    public static function defaultFilters(): array
    {
        $today = CarbonImmutable::now(self::TZ);

        return [
            'from' => $today->subDays(29)->toDateString(),
            'to' => $today->toDateString(),
            'group_by' => self::DAY,
        ];
    }

    /** Khoảng [from, to] theo UTC để truy vấn DB. */
    public function utcRange(): array
    {
        return [$this->from->utc(), $this->to->utc()];
    }

    /** @return array<string, string> khoá mốc => nhãn trục ngang (đủ mọi mốc trong khoảng) */
    public function buckets(): array
    {
        $multiYear = $this->from->year !== $this->to->year;
        $start = match ($this->groupBy) {
            self::WEEK => $this->from->startOfWeek(CarbonInterface::MONDAY),
            self::MONTH => $this->from->startOfMonth(),
            default => $this->from,
        };
        $step = match ($this->groupBy) {
            self::WEEK => '1 week',
            self::MONTH => '1 month',
            default => '1 day',
        };

        $buckets = [];
        foreach (CarbonPeriod::create($start, $step, $this->to) as $date) {
            $date = CarbonImmutable::instance($date);
            $buckets[$this->key($date)] = match ($this->groupBy) {
                self::WEEK => 'Tuần ' . $date->format($multiYear ? 'd/m/y' : 'd/m'),
                self::MONTH => 'Th' . $date->format('n/Y'),
                default => $date->format($multiYear ? 'd/m/y' : 'd/m'),
            };
        }

        return $buckets;
    }

    /** Khoá mốc của một thời điểm (đổi sang giờ VN trước khi gom). */
    public function key(CarbonInterface $at): string
    {
        $date = CarbonImmutable::instance($at)->timezone(self::TZ);

        return match ($this->groupBy) {
            self::WEEK => $date->startOfWeek(CarbonInterface::MONDAY)->toDateString(),
            self::MONTH => $date->format('Y-m'),
            default => $date->toDateString(),
        };
    }

    public function label(): string
    {
        $unit = match ($this->groupBy) {
            self::WEEK => 'theo tuần',
            self::MONTH => 'theo tháng',
            default => 'theo ngày',
        };

        return $this->from->format('d/m/Y') . ' – ' . $this->to->format('d/m/Y') . ' · ' . $unit;
    }

    public static function count(string $from, string $to, string $groupBy): int
    {
        $a = CarbonImmutable::parse($from);
        $b = CarbonImmutable::parse($to);

        return match ($groupBy) {
            self::WEEK => (int) ceil(($a->diffInDays($b) + 1) / 7),
            self::MONTH => (int) $a->startOfMonth()->diffInMonths($b->startOfMonth()) + 1,
            default => (int) $a->diffInDays($b) + 1,
        };
    }

    /** Nhãn thứ trong tuần, T2 → CN (ISO 1..7). */
    public static function weekdayLabels(): array
    {
        return [1 => 'T2', 2 => 'T3', 3 => 'T4', 4 => 'T5', 5 => 'T6', 6 => 'T7', 7 => 'CN'];
    }
}
