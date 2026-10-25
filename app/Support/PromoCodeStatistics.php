<?php

namespace App\Support;

use App\Models\PromoCodeRedemption;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Số liệu thống kê mã khuyến mãi theo bộ lọc của trang Thống kê › Mã khuyến mãi.
 *
 * Bộ lọc: codes (id mã, trống = tất cả) + from / to / group_by (xem TimeBuckets).
 * Doanh thu = tiền thực thu của đơn có dùng mã (giá gốc − tiền giảm).
 */
class PromoCodeStatistics
{
    public const TZ = TimeBuckets::TZ;

    public const GROUP_DAY = TimeBuckets::DAY;

    public const GROUP_WEEK = TimeBuckets::WEEK;

    public const GROUP_MONTH = TimeBuckets::MONTH;

    private TimeBuckets $time;

    /** @var list<int> */
    private array $codeIds;

    public function __construct(?array $filters)
    {
        $this->time = new TimeBuckets($filters);
        $this->codeIds = array_values(array_map('intval', array_filter((array) ($filters['codes'] ?? []))));
    }

    public static function defaultFilters(): array
    {
        return ['codes' => [], ...TimeBuckets::defaultFilters()];
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
        $buckets = $this->time->buckets();
        $usage = array_fill_keys(array_keys($buckets), 0);
        $revenue = array_fill_keys(array_keys($buckets), 0);

        // Gom theo giờ VN trong PHP để chạy giống nhau trên MySQL và SQLite
        $this->query()
            ->select(['id', 'created_at', 'order_amount', 'discount_amount'])
            ->lazyById(1000)
            ->each(function (PromoCodeRedemption $r) use (&$usage, &$revenue) {
                $key = $this->time->key(CarbonImmutable::parse($r->created_at));

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
        return $this->time->label();
    }

    public static function bucketCount(string $from, string $to, string $groupBy): int
    {
        return TimeBuckets::count($from, $to, $groupBy);
    }

    private function query(): Builder
    {
        return PromoCodeRedemption::query()
            ->whereBetween('created_at', $this->time->utcRange())
            ->when($this->codeIds, fn (Builder $query) => $query->whereIn('promo_code_id', $this->codeIds));
    }
}
