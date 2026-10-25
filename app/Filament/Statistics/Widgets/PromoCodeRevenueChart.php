<?php

namespace App\Filament\Statistics\Widgets;

class PromoCodeRevenueChart extends PromoCodeTrendChart
{
    protected function metric(): string
    {
        return 'revenue';
    }

    protected function label(): string
    {
        return 'Doanh thu từ đơn có mã';
    }

    protected function barColor(): string
    {
        return '#047857'; // emerald-700
    }

    protected function isMoney(): bool
    {
        return true;
    }
}
