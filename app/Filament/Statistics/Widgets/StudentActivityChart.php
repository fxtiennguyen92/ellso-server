<?php

namespace App\Filament\Statistics\Widgets;

use App\Support\StudentActivityStatistics;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/** Nền chung cho các biểu đồ của Thống kê › Hoạt động học viên (nhận bộ lọc khi bấm "Hiển thị"). */
abstract class StudentActivityChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected const GRID = 'rgba(148, 163, 184, 0.18)';

    protected const BAR = '#10b981'; // emerald-500

    /** Màu cố định theo nền tảng (đã kiểm tra phân biệt được với người mù màu, cả nền sáng và tối). */
    protected const PLATFORM_COLORS = [
        'ios' => '#047857',
        'android' => '#d97706',
        'web' => '#6366f1',
        'other' => '#94a3b8',
    ];

    protected function stats(): StudentActivityStatistics
    {
        return new StudentActivityStatistics($this->pageFilters);
    }

    protected function bar(string $label, array $data): array
    {
        return [
            'label' => $label,
            'data' => array_values($data),
            'backgroundColor' => self::BAR,
            'hoverBackgroundColor' => self::BAR,
            'borderWidth' => 0,
            'borderRadius' => 4,
            'borderSkipped' => 'start',
            'maxBarThickness' => 32,
        ];
    }

    /** Tuỳ chọn chung cho biểu đồ cột một chuỗi số lượt. */
    protected function barOptions(string $tooltipTitle = ''): RawJs
    {
        $title = $tooltipTitle !== '' ? "title: (items) => {$tooltipTitle}," : '';

        return RawJs::make(<<<JS
            {
                animation: { duration: 250 },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        displayColors: false,
                        callbacks: {
                            {$title}
                            label: (ctx) => ' ' + new Intl.NumberFormat('vi-VN').format(ctx.parsed.y) + ' lượt mở app',
                        },
                    },
                },
                scales: {
                    x: { grid: { display: false }, ticks: { autoSkip: true, maxRotation: 0 } },
                    y: {
                        beginAtZero: true,
                        border: { display: false },
                        grid: { color: '{$this->grid()}' },
                        ticks: { precision: 0 },
                    },
                },
            }
        JS);
    }

    protected function grid(): string
    {
        return self::GRID;
    }
}
