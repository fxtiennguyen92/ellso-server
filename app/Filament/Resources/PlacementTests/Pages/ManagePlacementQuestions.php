<?php

namespace App\Filament\Resources\PlacementTests\Pages;

use App\Enums\PlacementQuestionType;
use App\Filament\Resources\PlacementTests\Pages\Concerns\SimulatesPlacementTest;
use App\Filament\Resources\PlacementTests\PlacementTestResource;
use App\Models\PlacementQuestion;
use App\Models\PlacementTest;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/**
 * Tab "Câu hỏi": câu chọn 1 / chọn nhiều / sắp xếp. Mỗi đáp án có ô điểm cho từng phân loại.
 * Câu sắp xếp: điểm của mục × trọng số vị trí (hạng 1, 2, …) mà học viên xếp mục đó.
 */
class ManagePlacementQuestions extends ManageRelatedRecords
{
    use SimulatesPlacementTest;

    protected static string $resource = PlacementTestResource::class;

    protected static string $relationship = 'questions';

    protected static ?string $navigationLabel = 'Câu hỏi';

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected static ?string $title = 'Câu hỏi';

    protected static ?string $breadcrumb = 'Câu hỏi';

    protected static ?string $modelLabel = 'câu hỏi';

    public function getSubheading(): ?string
    {
        return $this->test()->title;
    }

    protected function getHeaderActions(): array
    {
        return [$this->simulateAction()];
    }

    public function form(Schema $schema): Schema
    {
        $dimensions = $this->test()->dimensions()->get(['id', 'name']);

        return $schema->components([
            Grid::make(1)->columnSpanFull()->schema([
                ToggleButtons::make('type')
                    ->label('Dạng câu hỏi')
                    ->options(PlacementQuestionType::class)
                    ->default(PlacementQuestionType::Single)
                    ->inline()
                    ->required()
                    ->live(),
                Textarea::make('prompt')
                    ->label('Câu hỏi')
                    ->rows(2)
                    ->required()
                    ->maxLength(1000),
                TextInput::make('hint')
                    ->label('Gợi ý dưới câu hỏi')
                    ->placeholder(fn (Get $get) => $this->isOrdering($get)
                        ? 'VD: Kéo để xếp từ phù hợp nhất đến ít phù hợp nhất'
                        : 'VD: Chọn đáp án gần với bạn nhất')
                    ->maxLength(300),
                Grid::make(2)
                    ->visible(fn (Get $get) => $this->typeOf($get) === PlacementQuestionType::Multiple)
                    ->schema([
                        TextInput::make('min_choices')->label('Chọn ít nhất')->integer()->minValue(1)->placeholder('1'),
                        TextInput::make('max_choices')->label('Chọn nhiều nhất')->integer()->minValue(1)->placeholder('Không giới hạn'),
                    ]),

                $dimensions->isEmpty()
                    ? Callout::make('Chưa có phân loại nào')
                        ->description('Tạo phân loại ở tab "Phân loại & mức độ" trước, rồi quay lại đặt điểm cho từng đáp án.')
                        ->warning()
                    : Callout::make(fn (Get $get) => $this->isOrdering($get)
                        ? 'Điểm của mỗi mục sẽ được nhân với trọng số của vị trí học viên xếp mục đó.'
                        : 'Học viên chọn đáp án nào thì cộng điểm của đáp án đó vào từng phân loại.')
                        ->description('Để trống = 0 điểm. Có thể nhập số âm để trừ điểm.')
                        ->info(),

                Repeater::make('options')
                    ->label(fn (Get $get) => $this->isOrdering($get) ? 'Các mục cần sắp xếp' : 'Đáp án')
                    ->relationship()
                    ->orderColumn('sort_order')
                    ->minItems(2)
                    ->maxItems(10)
                    ->defaultItems(2)
                    ->addActionLabel(fn (Get $get) => $this->isOrdering($get) ? 'Thêm mục' : 'Thêm đáp án')
                    ->live()
                    ->itemLabel(fn (array $state) => $this->optionLabel($state, $dimensions))
                    ->collapsible()
                    ->schema([
                        TextInput::make('label')
                            ->hiddenLabel()
                            ->placeholder('Nội dung đáp án')
                            ->required()
                            ->maxLength(500)
                            ->live(onBlur: true),
                        Grid::make(['default' => 2, 'md' => min(4, max(1, $dimensions->count()))])
                            ->visible($dimensions->isNotEmpty())
                            ->schema($dimensions->map(fn ($d) => TextInput::make('scores.' . $d->id)
                                ->label($d->name)
                                ->integer()
                                ->placeholder('0')
                                ->prefix('+/−')
                                ->live(onBlur: true))->all()),
                    ]),

                Section::make('Trọng số theo vị trí')
                    ->description('Hạng 1 là mục học viên xếp đầu tiên. Để trống = giá trị gợi ý (hạng cuối = 0).')
                    ->compact()
                    ->visible(fn (Get $get) => $this->isOrdering($get))
                    ->schema(fn (Get $get) => [
                        Grid::make(['default' => 3, 'md' => 5])->schema(
                            collect(range(0, max(0, count($get('options') ?? []) - 1)))
                                ->map(fn ($i) => TextInput::make('position_weights.' . $i)
                                    ->label('Hạng ' . ($i + 1))
                                    ->integer()
                                    ->prefix('×')
                                    ->placeholder((string) (PlacementQuestion::defaultWeights(count($get('options') ?? []))[$i] ?? 0)))
                                ->all()
                        ),
                    ]),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        $dimensions = $this->test()->dimensions()->pluck('name', 'id');

        return $table
            ->recordTitleAttribute('prompt')
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->modifyQueryUsing(fn ($query) => $query->with('options'))
            ->emptyStateHeading('Chưa có câu hỏi')
            ->emptyStateDescription('Thêm câu hỏi chọn 1, chọn nhiều hoặc sắp xếp. Mỗi đáp án cộng điểm cho các phân loại.')
            ->columns([
                TextColumn::make('number')
                    ->label('#')
                    ->rowIndex()
                    ->color('gray'),
                TextColumn::make('prompt')
                    ->label('Câu hỏi')
                    ->wrap()
                    ->limit(140)
                    ->description(fn (PlacementQuestion $record) => $record->options->pluck('label')->map(fn ($l) => str($l)->limit(40))->join(' · ')),
                TextColumn::make('type')
                    ->label('Dạng')
                    ->badge(),
                TextColumn::make('scoring')
                    ->label('Cộng điểm cho')
                    ->state(fn (PlacementQuestion $record) => $this->scoringHtml($record, $dimensions->all()))
                    ->html(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Thêm câu hỏi')
                    ->icon(Heroicon::Plus)
                    ->color('success')
                    ->modalWidth('4xl')
                    ->mutateDataUsing(function (array $data) {
                        $data['sort_order'] = (int) $this->test()->questions()->max('sort_order') + 1;

                        return $this->cleanQuestion($data);
                    })
                    ->createAnother(true),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()
                        ->modalWidth('4xl')
                        ->mutateDataUsing(fn (array $data) => $this->cleanQuestion($data)),
                    Action::make('duplicate')
                        ->label('Nhân bản')
                        ->icon(Heroicon::DocumentDuplicate)
                        ->action(function (PlacementQuestion $record) {
                            $copy = $record->replicate();
                            $copy->sort_order = (int) $this->test()->questions()->max('sort_order') + 1;
                            $copy->save();
                            foreach ($record->options as $option) {
                                $copy->options()->create($option->only(['label', 'scores', 'sort_order']));
                            }
                            Notification::make()->title('Đã nhân bản câu hỏi')->success()->send();
                        }),
                    DeleteAction::make()
                        ->modalDescription('Xoá câu hỏi và các đáp án. Bài làm cũ vẫn giữ kết quả.'),
                ]),
            ]);
    }

    private function test(): PlacementTest
    {
        /** @var PlacementTest */
        return $this->getOwnerRecord();
    }

    private function typeOf(Get $get): ?PlacementQuestionType
    {
        $type = $get('type');

        return $type instanceof PlacementQuestionType ? $type : PlacementQuestionType::tryFrom((string) $type);
    }

    private function isOrdering(Get $get): bool
    {
        return $this->typeOf($get) === PlacementQuestionType::Ordering;
    }

    /** Bỏ thiết lập không thuộc dạng câu hỏi đang chọn. */
    private function cleanQuestion(array $data): array
    {
        $type = $data['type'] instanceof PlacementQuestionType ? $data['type'] : PlacementQuestionType::from($data['type']);

        if ($type !== PlacementQuestionType::Multiple) {
            $data['min_choices'] = null;
            $data['max_choices'] = null;
        } elseif (filled($data['min_choices'] ?? null) && filled($data['max_choices'] ?? null) && $data['max_choices'] < $data['min_choices']) {
            $data['max_choices'] = $data['min_choices'];
        }

        if ($type !== PlacementQuestionType::Ordering) {
            $data['position_weights'] = null;
        } else {
            $weights = array_map(fn ($w) => $w === null || $w === '' ? null : (int) $w, (array) ($data['position_weights'] ?? []));
            ksort($weights);
            $data['position_weights'] = array_filter($weights, fn ($w) => $w !== null) === [] ? null : array_values($weights);
        }

        return $data;
    }

    /** Nhãn gọn cho đáp án: "Học qua video · Tiếp thu +2, Tự học +1". */
    private function optionLabel(array $state, $dimensions): string
    {
        $label = filled($state['label'] ?? null) ? str($state['label'])->limit(60)->toString() : 'Đáp án mới';
        $parts = [];
        foreach ($dimensions as $d) {
            $p = (int) ($state['scores'][$d->id] ?? 0);
            if ($p !== 0) {
                $parts[] = $d->name . ' ' . ($p > 0 ? '+' : '') . $p;
            }
        }

        return $parts ? $label . '  ·  ' . implode(', ', $parts) : $label;
    }

    /** Chip cho mỗi phân loại mà câu hỏi có cộng điểm. */
    private function scoringHtml(PlacementQuestion $record, array $dimensions): HtmlString
    {
        $touched = [];
        foreach ($record->options as $option) {
            foreach ($dimensions as $id => $name) {
                if ($option->scoreFor($id) !== 0) {
                    $touched[$id] = $name;
                }
            }
        }

        if (! $touched) {
            return new HtmlString('<span style="color: var(--warning-600); font-size: 13px">Chưa đặt điểm</span>');
        }

        return new HtmlString(collect($touched)->map(fn ($name) => '<span style="display:inline-block;padding:1px 8px;margin:2px 4px 2px 0;border-radius:999px;font-size:12px;background:color-mix(in oklab, var(--primary-500) 10%, transparent);color:var(--primary-700)">' . e($name) . '</span>')->join(''));
    }
}
