<?php

namespace App\Filament\Statistics\Widgets;

use App\Support\PromoCodeStatistics;
use App\Support\Vnd;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Thẻ tổng quan trên trang Thống kê › Mã khuyến mãi (nhận bộ lọc khi bấm "Hiển thị").
 * Hai thẻ số lượng hẹp, hai thẻ tiền rộng hơn; tiền viết tắt (rê chuột để xem số đầy đủ).
 */
class PromoCodeStatsOverview extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected function getColumns(): int | array | null
    {
        return ['default' => 1, 'md' => 2, 'xl' => 10];
    }

    protected function getStats(): array
    {
        $s = (new PromoCodeStatistics($this->pageFilters))->summary();
        $narrow = ['default' => 1, 'md' => 1, 'xl' => 2];
        $wide = ['default' => 1, 'md' => 1, 'xl' => 3];
        $avgDiscount = $s['orders'] ? (int) round($s['discount'] / $s['orders']) : 0;

        return [
            Stat::make('Khách dùng', number_format($s['customers'], 0, ',', '.'))
                ->description('Không tính trùng')
                ->icon(Heroicon::OutlinedUsers)
                ->columnSpan($narrow),
            Stat::make('Đơn dùng mã', number_format($s['orders'], 0, ',', '.'))
                ->description($s['customers'] ? 'TB ' . number_format($s['orders'] / $s['customers'], 1, ',', '.') . ' đơn/khách' : 'Chưa có đơn')
                ->icon(Heroicon::OutlinedShoppingBag)
                ->columnSpan($narrow),
            Stat::make('Tổng tiền đã giảm', Vnd::short($s['discount']))
                ->description($s['orders'] ? 'Trung bình ' . Vnd::short($avgDiscount) . '/đơn' : '—')
                ->icon(Heroicon::OutlinedReceiptPercent)
                ->extraAttributes(['title' => Vnd::full($s['discount'])])
                ->columnSpan($wide),
            Stat::make('Doanh thu từ đơn có mã', Vnd::short($s['revenue']))
                ->description('Giá gốc ' . Vnd::short($s['gross']) . ' − tiền giảm ' . Vnd::short($s['discount']))
                ->icon(Heroicon::OutlinedBanknotes)
                ->extraAttributes(['title' => Vnd::full($s['revenue'])])
                ->columnSpan($wide),
        ];
    }
}
