<?php

namespace App\Filament\Statistics\Widgets;

use App\Support\StudentActivityStatistics;
use Filament\Support\RawJs;

/** Tỉ lệ lượt mở app theo nền tảng trong khoảng đã lọc. */
class PlatformShareChart extends StudentActivityChart
{
    protected ?string $maxHeight = '240px';

    public function getHeading(): string
    {
        return 'Tỉ lệ nền tảng';
    }

    public function getDescription(): string
    {
        return 'Theo lượt mở app';
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        // Giữ thứ tự cố định iOS → Android → Web → Khác; bỏ nền tảng không có lượt nào
        $platforms = array_filter($this->stats()->activity()['platforms']);

        return [
            'labels' => array_map(fn ($key) => StudentActivityStatistics::platformLabel($key), array_keys($platforms)),
            'datasets' => [[
                'label' => 'Lượt mở app',
                'data' => array_values($platforms),
                'backgroundColor' => array_values(array_intersect_key(self::PLATFORM_COLORS, $platforms)),
                'borderColor' => 'rgba(255, 255, 255, 0.9)',
                'borderWidth' => 2,
                'hoverOffset' => 4,
            ]],
        ];
    }

    /** Chú thích kèm %: iOS 52%. Không viết comment // trong chuỗi JS (Filament gộp thành 1 dòng). */
    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                animation: { duration: 250 },
                cutout: '58%',
                aspectRatio: 2,
                scales: { x: { display: false }, y: { display: false } },
                plugins: {
                    legend: {
                        display: true,
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            boxWidth: 8,
                            boxHeight: 8,
                            generateLabels: (chart) => {
                                const data = chart.data.datasets[0].data;
                                const total = data.reduce((a, b) => a + b, 0) || 1;
                                return chart.data.labels.map((label, i) => ({
                                    text: label + ' ' + Math.round(data[i] / total * 100) + '%',
                                    fillStyle: chart.data.datasets[0].backgroundColor[i],
                                    strokeStyle: chart.data.datasets[0].backgroundColor[i],
                                    pointStyle: 'circle',
                                    hidden: !chart.getDataVisibility(i),
                                    index: i,
                                }));
                            },
                        },
                    },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => {
                                const total = ctx.dataset.data.reduce((a, b) => a + b, 0) || 1;
                                const v = ctx.parsed;
                                return ' ' + ctx.label + ': ' + new Intl.NumberFormat('vi-VN').format(v) + ' lượt (' + (v / total * 100).toLocaleString('vi-VN', { maximumFractionDigits: 1 }) + '%)';
                            },
                        },
                    },
                },
            }
        JS);
    }
}
