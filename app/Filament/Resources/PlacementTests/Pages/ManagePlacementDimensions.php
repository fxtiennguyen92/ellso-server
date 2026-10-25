<?php

namespace App\Filament\Resources\PlacementTests\Pages;

use App\Filament\Resources\PlacementTests\Pages\Concerns\SimulatesPlacementTest;
use App\Filament\Resources\PlacementTests\PlacementTestResource;
use App\Models\PlacementDimension;
use App\Models\PlacementLevel;
use App\Models\PlacementTest;
use App\Services\PlacementScoring;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/**
 * Tab "Phân loại & mức độ": mỗi phân loại (VD: Khả năng tiếp thu) có các mức (Xuất sắc ≥ 12, Tốt ≥ 8, …).
 * Hiện khoảng điểm có thể đạt (tính từ điểm của các đáp án) để admin đặt ngưỡng cho đúng.
 */
class ManagePlacementDimensions extends ManageRelatedRecords
{
    use SimulatesPlacementTest;

    protected static string $resource = PlacementTestResource::class;

    protected static string $relationship = 'dimensions';

    protected static ?string $navigationLabel = 'Phân loại & mức độ';

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $title = 'Phân loại & mức độ';

    protected static ?string $breadcrumb = 'Phân loại & mức độ';

    protected static ?string $modelLabel = 'phân loại';

    /** @var array<int, array{0:int,1:int}>|null */
    private ?array $rangeCache = null;

    public function getSubheading(): ?string
    {
        return $this->test()->title . ' — mỗi phân loại có nhiều mức độ; học viên đạt mức có "điểm từ" cao nhất mà tổng điểm của họ chạm tới.';
    }

    protected function getHeaderActions(): array
    {
        return [$this->simulateAction()];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(1)->columnSpanFull()->schema([
                TextInput::make('name')
                    ->label('Tên phân loại')
                    ->placeholder('VD: Khả năng tiếp thu')
                    ->required()
                    ->maxLength(120),
                Textarea::make('description')
                    ->label('Mô tả')
                    ->rows(2)
                    ->helperText('Hiện dưới tên phân loại ở màn kết quả trên app.'),
                Callout::make(fn (?PlacementDimension $record) => $this->rangeText($record))
                    ->description('Đặt "Điểm từ" của các mức trong khoảng này. Điểm thấp hơn mọi mức → học viên nhận mức thấp nhất.')
                    ->info(),
                Repeater::make('levels')
                    ->label('Mức độ')
                    ->relationship()
                    ->reorderable(false)
                    ->minItems(1)
                    ->addActionLabel('Thêm mức độ')
                    ->collapsible()
                    ->itemLabel(fn (array $state) => filled($state['name'] ?? null)
                        ? $state['name'] . (filled($state['min_score'] ?? null) ? ' · từ ' . $state['min_score'] . ' điểm' : '')
                        : 'Mức mới')
                    ->schema([
                        Grid::make(3)->schema([
                            TextInput::make('name')
                                ->label('Tên mức')
                                ->placeholder('VD: Xuất sắc')
                                ->required()
                                ->maxLength(60)
                                ->live(onBlur: true)
                                ->columnSpan(2),
                            TextInput::make('min_score')
                                ->label('Điểm từ')
                                ->helperText('Đạt khi tổng điểm ≥ số này')
                                ->integer()
                                ->required()
                                ->live(onBlur: true),
                        ]),
                        Textarea::make('description')
                            ->label('Nhận xét cho học viên')
                            ->rows(2)
                            ->placeholder('VD: Bạn nắm bắt kiến thức mới rất nhanh, phù hợp lộ trình nâng cao.'),
                        Select::make('courses')
                            ->label('Gợi ý khoá học')
                            ->relationship('courses', 'title')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->helperText('App gợi ý các khoá này (đã xuất bản) khi học viên đạt mức.'),
                    ]),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->modifyQueryUsing(fn ($query) => $query->with('levels.courses'))
            ->emptyStateHeading('Chưa có phân loại')
            ->emptyStateDescription('Tạo phân loại trước (VD: Khả năng tiếp thu), sau đó đặt điểm cho từng đáp án ở tab Câu hỏi.')
            ->columns([
                TextColumn::make('name')
                    ->label('Phân loại')
                    ->weight('medium')
                    ->description(fn (PlacementDimension $record) => $record->description)
                    ->wrap(),
                TextColumn::make('levels_summary')
                    ->label('Mức độ (từ cao xuống thấp)')
                    ->state(fn (PlacementDimension $record) => $this->levelsHtml($record))
                    ->html(),
                TextColumn::make('range')
                    ->label('Điểm có thể đạt')
                    ->state(function (PlacementDimension $record) {
                        [$min, $max] = $this->ranges()[$record->id] ?? [0, 0];

                        return $min === 0 && $max === 0 ? 'Chưa có đáp án cộng điểm' : "{$min} → {$max}";
                    })
                    ->color(fn (string $state) => str_starts_with($state, 'Chưa') ? 'warning' : null)
                    ->alignCenter(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Thêm phân loại')
                    ->icon(Heroicon::Plus)
                    ->color('success')
                    ->modalWidth('3xl')
                    ->mutateDataUsing(function (array $data) {
                        $data['sort_order'] = (int) $this->test()->dimensions()->max('sort_order') + 1;

                        return $data;
                    }),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()->modalWidth('3xl'),
                    DeleteAction::make()
                        ->modalDescription('Xoá phân loại và các mức độ của nó. Điểm đã đặt cho phân loại này ở các đáp án sẽ không còn tác dụng. Bài làm cũ vẫn giữ kết quả.'),
                ]),
            ]);
    }

    private function test(): PlacementTest
    {
        /** @var PlacementTest */
        return $this->getOwnerRecord();
    }

    /** @return array<int, array{0:int,1:int}> */
    private function ranges(): array
    {
        return $this->rangeCache ??= app(PlacementScoring::class)->ranges($this->test()->fresh()->loadStructure());
    }

    private function rangeText(?PlacementDimension $record): string
    {
        if (! $record) {
            return 'Lưu phân loại rồi đặt điểm cho đáp án ở tab Câu hỏi — khoảng điểm có thể đạt sẽ hiện tại đây.';
        }
        [$min, $max] = $this->ranges()[$record->id] ?? [0, 0];

        return $min === 0 && $max === 0
            ? 'Chưa có đáp án nào cộng điểm cho phân loại này.'
            : "Với các câu hỏi hiện tại, điểm của phân loại này nằm trong khoảng {$min} → {$max}.";
    }

    /** Các mức dạng chip, cảnh báo mức không thể đạt. */
    private function levelsHtml(PlacementDimension $record): HtmlString
    {
        [, $max] = $this->ranges()[$record->id] ?? [0, 0];
        $levels = $record->levels->sortByDesc('min_score');

        if ($levels->isEmpty()) {
            return new HtmlString('<span style="color: var(--warning-600)">Chưa có mức độ</span>');
        }

        $chips = $levels->map(function (PlacementLevel $level) use ($max) {
            $unreachable = $level->min_score > $max;
            $style = $unreachable
                ? 'background: color-mix(in oklab, var(--danger-500) 12%, transparent); color: var(--danger-600);'
                : 'background: color-mix(in oklab, var(--primary-500) 10%, transparent); color: var(--primary-700);';
            $courses = $level->courses->count();

            return '<span style="display:inline-flex;gap:4px;align-items:center;padding:2px 8px;border-radius:999px;font-size:12px;margin:2px 4px 2px 0;' . $style . '"'
                . ($unreachable ? ' title="Không thể đạt: điểm tối đa hiện tại là ' . $max . '"' : '') . '>'
                . '<b>' . e($level->name) . '</b> ≥ ' . $level->min_score
                . ($courses ? ' · ' . $courses . ' khoá' : '')
                . ($unreachable ? ' · không thể đạt' : '')
                . '</span>';
        })->join('');

        return new HtmlString('<div style="display:flex;flex-wrap:wrap">' . $chips . '</div>');
    }
}
