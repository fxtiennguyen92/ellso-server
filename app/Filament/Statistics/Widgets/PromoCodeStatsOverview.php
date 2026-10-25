<?php

namespace App\Filament\Statistics\Widgets;

use App\Models\PromoCode;
use App\Support\PromoCodeStatistics;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Thẻ tổng quan trên trang Thống kê › Mã khuyến mãi (nhận bộ lọc khi bấm "Hiển thị"). */
class PromoCodeStatsOverview extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $s = (new PromoCodeStatistics($this->pageFilters))->summary();

        return [
            Stat::make('Khách dùng mã', number_format($s['customers'], 0, ',', '.'))
                ->description('Số học viên khác nhau')
                ->icon(Heroicon::OutlinedUsers),
            Stat::make('Đơn dùng mã', number_format($s['orders'], 0, ',', '.'))
                ->description($s['customers'] ? 'Trung bình ' . number_format($s['orders'] / $s['customers'], 1, ',', '.') . ' đơn/khách' : 'Chưa có đơn')
                ->icon(Heroicon::OutlinedShoppingBag),
            Stat::make('Tổng tiền đã giảm', PromoCode::money($s['discount']))
                ->description($s['orders'] ? 'Trung bình ' . PromoCode::money((int) round($s['discount'] / $s['orders'])) . '/đơn' : '—')
                ->icon(Heroicon::OutlinedReceiptPercent),
            Stat::make('Doanh thu từ đơn có mã', PromoCode::money($s['revenue']))
                ->description('Giá gốc ' . PromoCode::money($s['gross']) . ' − tiền giảm')
                ->icon(Heroicon::OutlinedBanknotes),
        ];
    }
}
