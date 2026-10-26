<?php

namespace App\Filament\Resources\PlacementTests\Pages;

use App\Filament\Resources\PlacementTests\Pages\Concerns\SimulatesPlacementTest;
use App\Filament\Resources\PlacementTests\PlacementTestResource;
use App\Models\Classification;
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

    protected static ?string $navigationLabel = 'Năng lực & mức độ';

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $title = 'Năng lực & mức độ';

    protected static ?string $breadcrumb = 'Năng lực & mức độ';

    protected static ?string $modelLabel = 'năng lực';

    /** @var array<int, array{0:int,1:int}>|null */
    private ?array $rangeCache = null;

    public function getSubheading(): ?string
    {
        return $this->test()->title . ' — mỗi năng lực có nhiều mức độ; học viên đạt mức có "điểm từ" cao nhất mà tổng điểm của họ chạm tới.';
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
                    ->label('Tên năng lực')
                    ->placeholder('VD: Khả năng tiếp thu')
                    ->required()
                    ->maxLength(120),
                Textarea::make('description')
                    ->label('Mô tả')
                    ->rows(2)
                    ->helperText('Hiện dưới tên năng lực ở màn kết quả trên app.'),
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
            ->emptyStateHeading('Chưa có năng lực')
            ->emptyStateDescription('Tạo năng lực trước (VD: Khả năng tiếp thu), sau đó đặt điểm cho từng đáp án ở tab Câu hỏi.')
            ->columns([
                TextColumn::make('name')
                    ->label('Năng lực')
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
                TextColumn::make('used_in')
                    ->label('Dùng trong lộ trình')
                    ->state(fn (PlacementDimension $record) => ($n = $this->coursesUsing($record)) ? "Dùng ở {$n} khoá" : 'Chưa khoá nào dùng')
                    ->color(fn (string $state) => str_starts_with($state, 'Dùng') ? 'success' : 'gray')
                    ->tooltip('Chọn ở Khoá học › Phân loại lộ trình (tên "Năng lực · …"), rồi đặt mức cho từng lộ trình.')
                    ->alignCenter(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Thêm năng lực')
                    ->icon(Heroicon::Plus)
                    ->color('primary')
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
                        ->modalDescription(fn (PlacementDimension $record) => 'Xoá năng lực và các mức độ của nó. Điểm đã đặt cho năng lực này ở các đáp án sẽ không còn tác dụng. Bài làm cũ vẫn giữ kết quả.'
                            . (($n = $this->coursesUsing($record)) ? " Năng lực này đang là điều kiện lộ trình ở {$n} khoá — điều kiện đó sẽ không còn khớp với học viên làm bài sau này." : '')),
                ]),
            ]);
    }

    /** Số khoá đang chọn năng lực này làm phân loại lộ trình. */
    private function coursesUsing(PlacementDimension $record): int
    {
        return Classification::query()
            ->where('type', Classification::TYPE_ABILITY)
            ->where('placement_dimension_key', $record->key)
            ->withCount('courses')
            ->first()?->courses_count ?? 0;
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
            return 'Lưu năng lực rồi đặt điểm cho đáp án ở tab Câu hỏi — khoảng điểm có thể đạt sẽ hiện tại đây.';
        }
        [$min, $max] = $this->ranges()[$record->id] ?? [0, 0];

        return $min === 0 && $max === 0
            ? 'Chưa có đáp án nào cộng điểm cho năng lực này.'
            : "Với các câu hỏi hiện tại, điểm của năng lực này nằm trong khoảng {$min} → {$max}.";
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
