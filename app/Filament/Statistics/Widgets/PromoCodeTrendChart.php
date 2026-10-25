<?php

namespace App\Filament\Statistics\Widgets;

use App\Support\PromoCodeStatistics;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Cột theo thời gian cho một chỉ số. Số lần dùng và doanh thu là hai biểu đồ riêng
 * cùng trục thời gian (không gộp hai trục dọc vào một biểu đồ để khỏi đọc sai tỉ lệ).
 */
abstract class PromoCodeTrendChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected int | string | array $columnSpan = 'full';

    protected ?string $maxHeight = '260px';

    protected ?string $pollingInterval = null;

    /** 'usage' | 'revenue' */
    abstract protected function metric(): string;

    abstract protected function label(): string;

    abstract protected function barColor(): string;

    abstract protected function isMoney(): bool;

    public function getHeading(): string
    {
        return $this->label();
    }

    public function getDescription(): string
    {
        return (new PromoCodeStatistics($this->pageFilters))->periodLabel();
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $series = (new PromoCodeStatistics($this->pageFilters))->series();

        return [
            'labels' => $series['labels'],
            'datasets' => [[
                'label' => $this->label(),
                'data' => $series[$this->metric()],
                'backgroundColor' => $this->barColor(),
                'hoverBackgroundColor' => $this->barColor(),
                'borderWidth' => 0,
                'borderRadius' => 4,
                'borderSkipped' => 'start',
                'maxBarThickness' => 32,
            ]],
        ];
    }

    protected function getOptions(): RawJs
    {
        $format = $this->isMoney()
            ? "new Intl.NumberFormat('vi-VN').format(v) + ' ₫'"
            : "new Intl.NumberFormat('vi-VN').format(v)";
        $tick = $this->isMoney()
            ? "v >= 1e9 ? (v / 1e9).toLocaleString('vi-VN') + ' tỷ' : v >= 1e6 ? (v / 1e6).toLocaleString('vi-VN') + ' tr' : v >= 1e3 ? (v / 1e3).toLocaleString('vi-VN') + 'k' : v"
            : "Number.isInteger(v) ? v : ''";

        return RawJs::make(<<<JS
            {
                animation: { duration: 250 },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        displayColors: false,
                        callbacks: { label: (ctx) => { const v = ctx.parsed.y; return {$format}; } },
                    },
                },
                scales: {
                    x: { grid: { display: false }, ticks: { autoSkip: true, maxRotation: 0 } },
                    y: {
                        beginAtZero: true,
                        border: { display: false },
                        grid: { color: 'rgba(148, 163, 184, 0.18)' },
                        ticks: { precision: 0, callback: (v) => {$tick} },
                    },
                },
            }
        JS);
    }
}
