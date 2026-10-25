<?php

namespace App\Filament\Statistics\Widgets;

use Filament\Support\RawJs;

/**
 * Biểu đồ lớn: cột = lượt mở app (trục trái, có lưới ngang),
 * đường = học viên đăng ký mới (trục phải), theo ngày / tuần / tháng.
 */
class AppOpensChart extends StudentActivityChart
{
    protected int | string | array $columnSpan = 'full';

    protected ?string $maxHeight = '300px';

    private const SIGNUP_COLOR = '#db2777'; // khác hẳn màu cột và màu nền tảng trên cùng trang

    public function getHeading(): string
    {
        return 'Lượt mở app và học viên mới';
    }

    public function getDescription(): string
    {
        $a = $this->stats()->activity();
        $n = fn ($v) => number_format($v, 0, ',', '.');

        return $this->stats()->time()->label()
            . ' · ' . $n($a['total']) . ' lượt từ ' . $n($a['students']) . ' học viên'
            . ($a['total'] ? ' · trung bình ' . number_format($a['avg_minutes'], 1, ',', '.') . ' phút/lượt' : '')
            . ' · ' . $n($a['signups_total']) . ' học viên mới';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $a = $this->stats()->activity();

        return [
            'labels' => $a['labels'],
            'datasets' => [
                [...$this->bar('Lượt mở app', $a['opens']), 'yAxisID' => 'y', 'order' => 1],
                [
                    'type' => 'line',
                    'label' => 'Học viên đăng ký mới',
                    'data' => $a['signups'],
                    'yAxisID' => 'y1',
                    'borderColor' => self::SIGNUP_COLOR,
                    'backgroundColor' => self::SIGNUP_COLOR,
                    'pointBackgroundColor' => self::SIGNUP_COLOR,
                    'pointBorderColor' => self::SIGNUP_COLOR,
                    'pointHoverBackgroundColor' => self::SIGNUP_COLOR,
                    'borderWidth' => 2,
                    'pointRadius' => 0,             // đường mượt, không chấm; rê chuột mới hiện điểm
                    'pointHoverRadius' => 4,
                    'tension' => 0.4,
                    'order' => 0,
                ],
            ],
        ];
    }

    protected function getOptions(): RawJs
    {
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
                            label: (ctx) => ' ' + ctx.dataset.label + ': ' + new Intl.NumberFormat('vi-VN').format(ctx.parsed.y),
                        },
                    },
                },
                scales: {
                    x: { grid: { display: false }, ticks: { autoSkip: true, maxRotation: 0 } },
                    y: {
                        position: 'left',
                        beginAtZero: true,
                        border: { display: false },
                        grid: { color: '{$this->grid()}' },
                        title: { display: true, text: 'Lượt mở app' },
                        ticks: { precision: 0 },
                    },
                    y1: {
                        position: 'right',
                        beginAtZero: true,
                        border: { display: false },
                        grid: { display: false, drawOnChartArea: false },
                        title: { display: true, text: 'Học viên mới' },
                        min: 0,
                        ticks: { precision: 0 },
                    },
                },
            }
        JS);
    }
}
