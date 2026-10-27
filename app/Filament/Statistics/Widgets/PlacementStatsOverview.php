<?php

namespace App\Filament\Statistics\Widgets;

use App\Support\PlacementStatistics;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Thẻ tổng quan Thống kê › Kiểm tra đầu vào (theo bộ lọc). */
class PlacementStatsOverview extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected int | string | array $columnSpan = 'full';

    protected function getColumns(): int | array | null
    {
        return ['default' => 1, 'md' => 3];
    }

    protected function getStats(): array
    {
        $s = (new PlacementStatistics($this->pageFilters))->summary();
        $n = fn (int $v) => number_format($v, 0, ',', '.');
        $pct = rtrim(rtrim(number_format($s['completion_rate'], 1, ',', '.'), '0'), ',') . '%';

        return [
            Stat::make('Lượt làm bài', $n($s['attempts']))
                ->description($s['retakes'] ? $n($s['retakes']) . ' lượt làm lại' : 'Không có lượt làm lại')
                ->icon(Heroicon::OutlinedClipboardDocumentCheck),
            Stat::make('Học viên đã làm', $n($s['students']))
                ->description('Không tính trùng')
                ->icon(Heroicon::OutlinedUsers),
            Stat::make('Học viên mới đã làm bài', $s['signups'] ? $pct : '—')
                ->description($n($s['signups_done']) . ' / ' . $n($s['signups']) . ' học viên đăng ký trong khoảng')
                ->icon(Heroicon::OutlinedUserPlus),
        ];
    }
}
