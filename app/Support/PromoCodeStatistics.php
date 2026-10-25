<?php

namespace App\Support;

use App\Models\PromoCodeRedemption;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Số liệu thống kê mã khuyến mãi theo bộ lọc của trang Thống kê › Mã khuyến mãi.
 *
 * Bộ lọc: codes (id mã, trống = tất cả), from / to (Y-m-d, giờ VN), group_by (day|week|month).
 * Doanh thu = tiền thực thu của đơn có dùng mã (giá gốc − tiền giảm).
 */
class PromoCodeStatistics
{
    public const TZ = 'Asia/Ho_Chi_Minh';

    public const GROUP_DAY = 'day';

    public const GROUP_WEEK = 'week';

    public const GROUP_MONTH = 'month';

    private CarbonImmutable $from;

    private CarbonImmutable $to;

    private string $groupBy;

    /** @var list<int> */
    private array $codeIds;

    public function __construct(?array $filters)
    {
        $filters ??= self::defaultFilters();

        $this->from = CarbonImmutable::parse($filters['from'] ?? self::defaultFilters()['from'], self::TZ)->startOfDay();
        $this->to = CarbonImmutable::parse($filters['to'] ?? self::defaultFilters()['to'], self::TZ)->endOfDay();

        if ($this->to->lt($this->from)) {
            [$this->from, $this->to] = [$this->to->startOfDay(), $this->from->endOfDay()];
        }

        $this->groupBy = in_array($filters['group_by'] ?? null, [self::GROUP_DAY, self::GROUP_WEEK, self::GROUP_MONTH], true)
            ? $filters['group_by']
            : self::GROUP_DAY;

        $this->codeIds = array_values(array_map('intval', array_filter((array) ($filters['codes'] ?? []))));
    }

    /** 30 ngày gần nhất, theo ngày, tất cả mã. */
    public static function defaultFilters(): array
    {
        $today = CarbonImmutable::now(self::TZ);

        return [
            'codes' => [],
            'from' => $today->subDays(29)->toDateString(),
            'to' => $today->toDateString(),
            'group_by' => self::GROUP_DAY,
        ];
    }

    /** @return array{customers: int, orders: int, discount: int, revenue: int, gross: int} */
    public function summary(): array
    {
        $row = $this->query()
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('COUNT(DISTINCT user_id) as customers')
            ->selectRaw('COALESCE(SUM(discount_amount), 0) as discount')
            ->selectRaw('COALESCE(SUM(order_amount), 0) as gross')
            ->toBase()
            ->first();

        return [
            'customers' => (int) $row->customers,
            'orders' => (int) $row->orders,
            'discount' => (int) $row->discount,
            'gross' => (int) $row->gross,
            'revenue' => (int) $row->gross - (int) $row->discount,
        ];
    }

    /**
     * Chuỗi theo thời gian, đủ mọi mốc trong khoảng (mốc không có đơn = 0).
     *
     * @return array{labels: list<string>, usage: list<int>, revenue: list<int>}
     */
    public function series(): array
    {
        $buckets = $this->buckets();
        $usage = array_fill_keys(array_keys($buckets), 0);
        $revenue = array_fill_keys(array_keys($buckets), 0);

        // Gom theo giờ VN trong PHP để chạy giống nhau trên MySQL và SQLite
        $this->query()
            ->select(['created_at', 'order_amount', 'discount_amount'])
            ->orderBy('id')
            ->lazy(1000)
            ->each(function (PromoCodeRedemption $r) use (&$usage, &$revenue) {
                $key = $this->bucketKey(CarbonImmutable::parse($r->created_at)->timezone(self::TZ));

                if (isset($usage[$key])) {
                    $usage[$key]++;
                    $revenue[$key] += $r->order_amount - $r->discount_amount;
                }
            });

        return [
            'labels' => array_values($buckets),
            'usage' => array_values($usage),
            'revenue' => array_values($revenue),
        ];
    }

    public function periodLabel(): string
    {
        $unit = match ($this->groupBy) {
            self::GROUP_WEEK => 'theo tuần',
            self::GROUP_MONTH => 'theo tháng',
            default => 'theo ngày',
        };

        return $this->from->format('d/m/Y') . ' – ' . $this->to->format('d/m/Y') . ' · ' . $unit;
    }

    private function query(): Builder
    {
        return PromoCodeRedemption::query()
            ->whereBetween('created_at', [$this->from->utc(), $this->to->utc()])
            ->when($this->codeIds, fn (Builder $q) => $q->whereIn('promo_code_id', $this->codeIds));
    }

    /** @return array<string, string> khoá mốc => nhãn trục ngang */
    private function buckets(): array
    {
        $multiYear = $this->from->year !== $this->to->year;
        $start = match ($this->groupBy) {
            self::GROUP_WEEK => $this->from->startOfWeek(CarbonImmutable::MONDAY),
            self::GROUP_MONTH => $this->from->startOfMonth(),
            default => $this->from,
        };
        $step = match ($this->groupBy) {
            self::GROUP_WEEK => '1 week',
            self::GROUP_MONTH => '1 month',
            default => '1 day',
        };

        $buckets = [];
        foreach (CarbonPeriod::create($start, $step, $this->to) as $date) {
            $date = CarbonImmutable::instance($date);
            $buckets[$this->bucketKey($date)] = match ($this->groupBy) {
                self::GROUP_WEEK => 'Tuần ' . $date->format($multiYear ? 'd/m/y' : 'd/m'),
                self::GROUP_MONTH => 'Th' . $date->format('n/Y'),
                default => $date->format($multiYear ? 'd/m/y' : 'd/m'),
            };
        }

        return $buckets;
    }

    private function bucketKey(CarbonImmutable $date): string
    {
        return match ($this->groupBy) {
            self::GROUP_WEEK => $date->startOfWeek(CarbonImmutable::MONDAY)->toDateString(),
            self::GROUP_MONTH => $date->format('Y-m'),
            default => $date->toDateString(),
        };
    }

    /** Số mốc trên trục ngang (để chặn khoảng quá dài khi xem theo ngày). */
    public static function bucketCount(string $from, string $to, string $groupBy): int
    {
        $a = CarbonImmutable::parse($from);
        $b = CarbonImmutable::parse($to);

        return match ($groupBy) {
            self::GROUP_WEEK => (int) ceil(($a->diffInDays($b) + 1) / 7),
            self::GROUP_MONTH => (int) $a->startOfMonth()->diffInMonths($b->startOfMonth()) + 1,
            default => (int) $a->diffInDays($b) + 1,
        };
    }
}
