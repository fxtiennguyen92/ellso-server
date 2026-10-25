<?php

namespace App\Filament\Pages\Statistics;

use App\Filament\Statistics\Widgets\PromoCodeStatsOverview;
use App\Filament\Statistics\Widgets\PromoCodeTrendChart;
use App\Models\PromoCode;
use App\Support\PromoCodeStatistics;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ToggleButtons;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Thống kê › Mã khuyến mãi. Chọn bộ lọc rồi bấm "Hiển thị": thẻ tổng quan và biểu đồ
 * nhận bộ lọc đã áp dụng qua $filters (Filament truyền cho widget thành pageFilters).
 */
class PromoCodeStatisticsPage extends Page
{
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string | UnitEnum | null $navigationGroup = 'Thống kê';

    protected static ?string $navigationLabel = 'Mã khuyến mãi';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Thống kê mã khuyến mãi';

    protected static ?string $slug = 'statistics/promo-codes';

    /** Lựa chọn đang nhập trên form (chưa áp dụng). */
    public ?array $data = [];

    /** Bộ lọc đã áp dụng — chỉ đổi khi bấm "Hiển thị". */
    public ?array $filters = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    public function mount(): void
    {
        $defaults = PromoCodeStatistics::defaultFilters();

        $this->form->fill($defaults);
        $this->filters = $defaults;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Grid::make(['default' => 1, 'md' => 2, 'xl' => 12])->schema([
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
                                ->where(fn ($q) => $q->where('code', 'like', '%' . strtoupper(trim($search)) . '%')
                                    ->orWhere('name', 'like', '%' . trim($search) . '%'))
                                ->limit(30),
                        ))
                        ->getOptionLabelsUsing(fn (array $values) => self::codeOptions(PromoCode::withTrashed()->whereKey($values)))
                        ->columnSpan(['default' => 1, 'md' => 2, 'xl' => 5]),
                    DatePicker::make('from')
                        ->label('Từ ngày')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->maxDate(now(PromoCodeStatistics::TZ))
                        ->required()
                        ->columnSpan(['default' => 1, 'xl' => 2]),
                    DatePicker::make('to')
                        ->label('Đến ngày')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->required()
                        ->rule(fn (Get $get) => function (string $attribute, mixed $value, Closure $fail) use ($get) {
                            if ($value && $get('from') && $value < $get('from')) {
                                $fail('"Đến ngày" phải sau hoặc bằng "Từ ngày".');

                                return;
                            }

                            if ($value && $get('from') && PromoCodeStatistics::bucketCount($get('from'), $value, (string) $get('group_by')) > 400) {
                                $fail('Khoảng thời gian quá dài để xem theo mốc này. Hãy chọn xem theo tuần hoặc tháng.');
                            }
                        })
                        ->columnSpan(['default' => 1, 'xl' => 2]),
                    ToggleButtons::make('group_by')
                        ->label('Hiển thị theo')
                        ->options([
                            PromoCodeStatistics::GROUP_DAY => 'Ngày',
                            PromoCodeStatistics::GROUP_WEEK => 'Tuần',
                            PromoCodeStatistics::GROUP_MONTH => 'Tháng',
                        ])
                        ->grouped()
                        ->required()
                        ->columnSpan(['default' => 1, 'md' => 2, 'xl' => 3]),
                ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->schema([
                    Form::make([EmbeddedSchema::make('form')])
                        ->id('statistics-filters')
                        ->livewireSubmitHandler('apply')
                        ->footer([
                            Actions::make([
                                Action::make('apply')
                                    ->label('Hiển thị')
                                    ->icon(Heroicon::OutlinedFunnel)
                                    ->submit('apply'),
                                Action::make('reset')
                                    ->label('Đặt lại')
                                    ->color('gray')
                                    ->action(fn () => $this->resetFilters()),
                            ]),
                        ]),
                ]),
        ]);
    }

    public function apply(): void
    {
        $this->filters = $this->form->getState();
    }

    public function resetFilters(): void
    {
        $defaults = PromoCodeStatistics::defaultFilters();
        $this->form->fill($defaults);
        $this->filters = $defaults;
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
