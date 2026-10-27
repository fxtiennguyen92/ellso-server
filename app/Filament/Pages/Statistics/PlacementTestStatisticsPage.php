<?php

namespace App\Filament\Pages\Statistics;

use App\Filament\Statistics\Widgets\PlacementAttemptsChart;
use App\Filament\Statistics\Widgets\PlacementLevelChart;
use App\Filament\Statistics\Widgets\PlacementStatsOverview;
use App\Models\PlacementTest;
use App\Support\PlacementStatistics;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\WidgetConfiguration;

/**
 * Thống kê › Kiểm tra đầu vào: lượt làm bài + phân bố mức độ theo từng phân loại.
 */
class PlacementTestStatisticsPage extends StatisticsPage
{
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'Kiểm tra đầu vào';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Thống kê kiểm tra đầu vào';

    protected static ?string $slug = 'statistics/placement-test';

    protected function defaultFilters(): array
    {
        return PlacementStatistics::defaultFilters();
    }

    protected function extraFilterFields(): array
    {
        return [
            Select::make('test_id')
                ->label('Bài kiểm tra')
                ->options(fn () => PlacementTest::latest('id')->get()
                    ->mapWithKeys(fn (PlacementTest $t) => [$t->id => $t->title . ($t->is_active ? ' (đang dùng)' : '')]))
                ->required()
                ->columnSpan(['default' => 1, 'md' => 2, 'xl' => 5]),
        ];
    }

    /** @return list<class-string|WidgetConfiguration> */
    protected function getFooterWidgets(): array
    {
        $test = PlacementStatistics::resolveTest($this->filters['test_id'] ?? null);

        return [
            PlacementStatsOverview::class,
            PlacementAttemptsChart::class,
            ...($test?->dimensions()->pluck('id') ?? collect())
                ->map(fn (int $id) => PlacementLevelChart::make(['dimensionId' => $id]))
                ->all(),
        ];
    }

    public function getFooterWidgetsColumns(): int | array
    {
        return ['default' => 1, 'lg' => 3];
    }
}
