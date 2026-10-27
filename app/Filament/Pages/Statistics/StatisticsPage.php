<?php

namespace App\Filament\Pages\Statistics;

use App\Support\TimeBuckets;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\ToggleButtons;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Nền chung cho các màn Thống kê: thanh lọc (thêm trường riêng + Từ ngày / Đến ngày / Hiển thị theo)
 * với nút "Hiển thị". Bộ lọc chỉ áp dụng khi bấm nút: $filters được Filament truyền cho widget
 * thành pageFilters (widget dùng InteractsWithPageFilters).
 */
abstract class StatisticsPage extends Page
{
    protected static string | UnitEnum | null $navigationGroup = 'Thống kê';

    /** Lựa chọn đang nhập trên form (chưa áp dụng). */
    public ?array $data = [];

    /** Bộ lọc đã áp dụng — chỉ đổi khi bấm "Hiển thị". */
    public ?array $filters = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    /** Giá trị mặc định của bộ lọc. */
    abstract protected function defaultFilters(): array;

    /**
     * Trường lọc riêng của màn (đặt trước Từ ngày). Tổng độ rộng trên màn lớn: 12 cột.
     *
     * @return list<Component>
     */
    protected function extraFilterFields(): array
    {
        return [];
    }

    public function mount(): void
    {
        $this->resetFilters();
    }

    public function form(Schema $schema): Schema
    {
        $hasExtra = count($this->extraFilterFields()) > 0;

        return $schema
            ->statePath('data')
            ->components([
                Grid::make(['default' => 1, 'md' => 2, 'xl' => 12])->schema([
                    ...$this->extraFilterFields(),
                    DatePicker::make('from')
                        ->label('Từ ngày')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->maxDate(now(TimeBuckets::TZ))
                        ->required()
                        ->columnSpan(['default' => 1, 'xl' => $hasExtra ? 2 : 3]),
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

                            if ($value && $get('from')
                                && TimeBuckets::count($get('from'), $value, (string) $get('group_by')) > TimeBuckets::MAX_BUCKETS) {
                                $fail('Khoảng thời gian quá dài để xem theo mốc này. Hãy chọn xem theo tuần hoặc tháng.');
                            }
                        })
                        ->columnSpan(['default' => 1, 'xl' => $hasExtra ? 2 : 3]),
                    ToggleButtons::make('group_by')
                        ->label('Hiển thị theo')
                        ->options([
                            TimeBuckets::DAY => 'Ngày',
                            TimeBuckets::WEEK => 'Tuần',
                            TimeBuckets::MONTH => 'Tháng',
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
                                    ->icon(Heroicon::Funnel)
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
        $defaults = $this->defaultFilters();
        $this->form->fill($defaults);
        $this->filters = $defaults;
    }
}
