<?php

namespace App\Filament\Statistics\Widgets;

use App\Support\PromoCodeStatistics;
use App\Support\Vnd;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Một biểu đồ theo thời gian: cột = số lần sử dụng (trục trái), đường = doanh thu (trục phải).
 * Lưới ngang chỉ theo trục trái.
 */
class PromoCodeTrendChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected int | string | array $columnSpan = 'full';

    protected ?string $maxHeight = '320px';

    protected ?string $pollingInterval = null;

    private const USAGE_COLOR = '#10b981';   // emerald-500

    private const REVENUE_COLOR = '#f59e0b'; // spark-500

    public function getHeading(): string
    {
        return 'Số lần sử dụng và doanh thu';
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
            'datasets' => [
                [
                    'type' => 'bar',
                    'label' => 'Số lần sử dụng',
                    'data' => $series['usage'],
                    'yAxisID' => 'y',
                    'backgroundColor' => self::USAGE_COLOR,
                    'hoverBackgroundColor' => self::USAGE_COLOR,
                    'borderWidth' => 0,
                    'borderRadius' => 4,
                    'borderSkipped' => 'start',
                    'maxBarThickness' => 32,
                    'order' => 1,
                ],
                [
                    'type' => 'line',
                    'label' => 'Doanh thu',
                    'data' => $series['revenue'],
                    'yAxisID' => 'y1',
                    'borderColor' => self::REVENUE_COLOR,
                    'backgroundColor' => self::REVENUE_COLOR,
                    'borderWidth' => 2,
                    'pointRadius' => count($series['labels']) > 45 ? 0 : 3,
                    'pointHoverRadius' => 5,
                    'tension' => 0.25,
                    'order' => 0, // vẽ trên cột
                ],
            ],
        ];
    }

    protected function getOptions(): RawJs
    {
        $short = Vnd::shortJs();

        return RawJs::make(<<<JS
            {
                animation: { duration: 250 },
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        align: 'end',
                        labels: { usePointStyle: true, boxWidth: 8, boxHeight: 8, sort: (a, b) => a.datasetIndex - b.datasetIndex },
                    },
                    tooltip: {
                        itemSort: (a, b) => a.datasetIndex - b.datasetIndex,
                        callbacks: {
                            label: (ctx) => {
                                const v = ctx.parsed.y;
                                if (ctx.dataset.yAxisID === 'y1') {
                                    return ' Doanh thu: ' + ({$short})(v) + ' (' + new Intl.NumberFormat('vi-VN').format(v) + ' ₫)';
                                }
                                return ' Số lần sử dụng: ' + new Intl.NumberFormat('vi-VN').format(v);
                            },
                        },
                    },
                },
                scales: {
                    x: { grid: { display: false }, ticks: { autoSkip: true, maxRotation: 0 } },
                    y: {
                        position: 'left',
                        beginAtZero: true,
                        border: { display: false },
                        grid: { color: 'rgba(148, 163, 184, 0.18)' },
                        title: { display: true, text: 'Số lần sử dụng' },
                        ticks: { precision: 0 },
                    },
                    y1: {
                        position: 'right',
                        beginAtZero: true,
                        border: { display: false },
                        grid: { display: false, drawOnChartArea: false },
                        title: { display: true, text: 'Doanh thu' },
                        ticks: { callback: (v) => ({$short})(v) },
                    },
                },
            }
        JS);
    }
}
