<?php

namespace App\Filament\Pages\Statistics;

use App\Filament\Statistics\Widgets\PromoCodeStatsOverview;
use App\Filament\Statistics\Widgets\PromoCodeTrendChart;
use App\Models\PromoCode;
use App\Support\PromoCodeStatistics;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;

/** Thống kê › Mã khuyến mãi. */
class PromoCodeStatisticsPage extends StatisticsPage
{
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?string $navigationLabel = 'Mã khuyến mãi';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Thống kê mã khuyến mãi';

    protected static ?string $slug = 'statistics/promo-codes';

    protected function defaultFilters(): array
    {
        return PromoCodeStatistics::defaultFilters();
    }

    protected function extraFilterFields(): array
    {
        return [
            Select::make('codes')
                ->label('Mã khuyến mãi')
                ->placeholder('Tất cả mã')
                ->multiple()
                ->searchable()
                ->searchPrompt('Gõ mã hoặc tên chương trình…')
                ->noSearchResultsMessage('Không tìm thấy mã.')
                ->options(fn () => self::codeOptions(PromoCode::withTrashed()->latest('id')->limit(30)))
                ->getSearchResultsUsing(fn (string $search) => self::codeOptions(
                    PromoCode::withTrashed()
                        ->where(fn ($query) => $query->where('code', 'like', '%' . strtoupper(trim($search)) . '%')
                            ->orWhere('name', 'like', '%' . trim($search) . '%'))
                        ->limit(30),
                ))
                ->getOptionLabelsUsing(fn (array $values) => self::codeOptions(PromoCode::withTrashed()->whereKey($values)))
                ->columnSpan(['default' => 1, 'md' => 2, 'xl' => 5]),
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [
            PromoCodeStatsOverview::class,
            PromoCodeTrendChart::class,
        ];
    }

    public function getFooterWidgetsColumns(): int | array
    {
        return 1;
    }

    /** @return array<int, string> id => "CODE · Tên chương trình" */
    private static function codeOptions($query): array
    {
        return $query->get(['id', 'code', 'name', 'deleted_at'])
            ->mapWithKeys(fn (PromoCode $p) => [
                $p->id => $p->code . ' · ' . $p->name . ($p->trashed() ? ' (đã xoá)' : ''),
            ])
            ->all();
    }
}
