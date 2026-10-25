<?php

namespace App\Filament\Statistics\Widgets;

use App\Models\PlacementDimension;
use App\Support\PlacementStatistics;
use Filament\Support\RawJs;

/** Phân bố mức độ của một phân loại (mỗi học viên lấy bài làm mới nhất trong khoảng). */
class PlacementLevelChart extends StudentActivityChart
{
    public ?int $dimensionId = null;

    protected ?string $maxHeight = '220px';

    /** Mức cao → thấp: đậm → nhạt (emerald). */
    private const RANK_COLORS = ['#047857', '#10b981', '#6ee7b7', '#a7f3d0', '#d1fae5'];

    private function rows(): array
    {
        return $this->dimensionId ? (new PlacementStatistics($this->pageFilters))->distribution($this->dimensionId) : [];
    }

    public function getHeading(): string
    {
        return PlacementDimension::find($this->dimensionId)?->name ?? 'Phân loại';
    }

    public function getDescription(): string
    {
        $total = array_sum(array_column($this->rows(), 'count'));

        return $total ? number_format($total, 0, ',', '.') . ' học viên · bài làm mới nhất' : 'Chưa có bài làm trong khoảng này';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $rows = $this->rows();

        return [
            'labels' => array_column($rows, 'name'),
            'datasets' => [[
                'label' => 'Học viên',
                'data' => array_column($rows, 'count'),
                'backgroundColor' => array_map(fn ($r) => self::RANK_COLORS[min($r['rank'], count(self::RANK_COLORS)) - 1], $rows),
                'borderWidth' => 0,
                'borderRadius' => 4,
                'maxBarThickness' => 28,
            ]],
        ];
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<JS
            {
                indexAxis: 'y',
                animation: { duration: 250 },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        displayColors: false,
                        callbacks: {
                            label: (ctx) => {
                                const total = ctx.dataset.data.reduce((a, b) => a + b, 0) || 1;
                                return ' ' + new Intl.NumberFormat('vi-VN').format(ctx.parsed.x) + ' học viên (' + (ctx.parsed.x / total * 100).toLocaleString('vi-VN', { maximumFractionDigits: 1 }) + '%)';
                            },
                        },
                    },
                },
                scales: {
                    x: { beginAtZero: true, border: { display: false }, grid: { color: '{$this->grid()}' }, ticks: { precision: 0 } },
                    y: { grid: { display: false } },
                },
            }
        JS);
    }
}
