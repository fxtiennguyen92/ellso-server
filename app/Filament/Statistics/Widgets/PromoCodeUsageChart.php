<?php

namespace App\Filament\Statistics\Widgets;

class PromoCodeUsageChart extends PromoCodeTrendChart
{
    protected function metric(): string
    {
        return 'usage';
    }

    protected function label(): string
    {
        return 'Số lần sử dụng mã';
    }

    protected function barColor(): string
    {
        return '#10b981'; // emerald-500
    }

    protected function isMoney(): bool
    {
        return false;
    }
}
