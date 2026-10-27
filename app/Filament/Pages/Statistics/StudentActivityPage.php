<?php

namespace App\Filament\Pages\Statistics;

use App\Filament\Statistics\Widgets\AppOpensChart;
use App\Filament\Statistics\Widgets\HourlyOpensChart;
use App\Filament\Statistics\Widgets\PlatformShareChart;
use App\Filament\Statistics\Widgets\StudentActivityOverview;
use App\Filament\Statistics\Widgets\WeekdayOpensChart;
use App\Support\StudentActivityStatistics;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

/**
 * Thống kê › Hoạt động học viên.
 * Thẻ trên cùng: toàn thời gian / hiện tại. Biểu đồ bên dưới: theo bộ lọc ngày.
 */
class StudentActivityPage extends StatisticsPage
{
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Hoạt động học viên';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Hoạt động học viên';

    protected static ?string $slug = 'statistics/student-activity';

    protected function defaultFilters(): array
    {
        return StudentActivityStatistics::defaultFilters();
    }

    protected function getHeaderWidgets(): array
    {
        return [StudentActivityOverview::class];
    }

    public function getHeaderWidgetsColumns(): int | array
    {
        return 1;
    }

    protected function getFooterWidgets(): array
    {
        return [
            AppOpensChart::class,
            PlatformShareChart::class,
            WeekdayOpensChart::class,
            HourlyOpensChart::class,
        ];
    }

    public function getFooterWidgetsColumns(): int | array
    {
        return ['default' => 1, 'lg' => 3];
    }
}
