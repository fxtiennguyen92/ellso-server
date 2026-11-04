<?php

namespace App\Filament\Resources\Exams\Pages;

use App\Filament\Resources\Exams\ExamResource;
use App\Models\Exam;
use App\Models\ExamQuestion;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/** Tab "Câu hỏi" của đề thi: chọn 1 / chọn nhiều, đánh dấu đáp án đúng, giải thích (tuỳ chọn). */
class ManageExamQuestions extends ManageRelatedRecords
{
    protected static string $resource = ExamResource::class;

    protected static string $relationship = 'questions';

    protected static ?string $navigationLabel = 'Câu hỏi';

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected static ?string $title = 'Câu hỏi';

    protected static ?string $breadcrumb = 'Câu hỏi';

    protected static ?string $modelLabel = 'câu hỏi';

    public function getSubheading(): ?string
    {
        $exam = $this->exam();
        $total = $exam->questions()->count();

        return $exam->title . ' — ' . ($exam->question_count && $total
            ? "ngân hàng {$total} câu, mỗi lần làm rút ngẫu nhiên {$exam->questionsPerAttempt($total)} câu"
                . ($exam->question_count > $total ? ' (đang thiếu ' . ($exam->question_count - $total) . ' câu so với số đã đặt)' : '')
            : "{$total} câu, làm hết")
            . ($exam->time_limit_minutes ? ", {$exam->time_limit_minutes} phút" : ', không giới hạn thời gian')
            . '. Đậu khi đúng ≥ ' . Exam::PASS_PERCENT . '%.';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(1)->columnSpanFull()->schema([
                ToggleButtons::make('type')
                    ->label('Dạng câu hỏi')
                    ->options(ExamQuestion::TYPES)
                    ->default('single')
                    ->inline()
                    ->required()
                    ->live(),
                Textarea::make('prompt')
                    ->label('Câu hỏi')
                    ->rows(2)
                    ->required()
                    ->maxLength(1000),
                Repeater::make('options')
                    ->label('Đáp án')
                    ->relationship()
                    ->orderColumn('sort_order')
                    ->minItems(2)
                    ->maxItems(8)
                    ->defaultItems(4)
                    ->addActionLabel('Thêm đáp án')
                    ->helperText(fn (Get $get) => $get('type') === 'multiple'
                        ? 'Bật "Đúng" cho mọi đáp án đúng. Học viên phải chọn đủ và đúng các đáp án này.'
                        : 'Bật "Đúng" cho đúng 1 đáp án.')
                    ->rule(fn (Get $get) => function (string $attribute, $value, Closure $fail) use ($get) {
                        $correct = collect($value)->filter(fn ($o) => (bool) ($o['is_correct'] ?? false))->count();
                        if ($correct === 0) {
                            $fail('Chưa đánh dấu đáp án đúng.');
                        } elseif ($get('type') !== 'multiple' && $correct > 1) {
                            $fail('Câu "Chọn 1" chỉ được có 1 đáp án đúng.');
                        }
                    })
                    ->table([
                        Repeater\TableColumn::make('Nội dung đáp án'),
                        Repeater\TableColumn::make('Đúng')->width('80px'),
                    ])
                    ->schema([
                        TextInput::make('label')
                            ->hiddenLabel()
                            ->required()
                            ->maxLength(500),
                        Toggle::make('is_correct')
                            ->hiddenLabel()
                            ->default(false),
                    ]),
                Textarea::make('explanation')
                    ->label('Giải thích (tuỳ chọn)')
                    ->rows(2)
                    ->helperText('Chỉ để giảng viên / admin xem lại. Học viên chỉ thấy câu nào đúng / sai.'),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('prompt')
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->modifyQueryUsing(fn ($query) => $query->with('options'))
            ->emptyStateHeading('Chưa có câu hỏi')
            ->emptyStateDescription('Thêm câu chọn 1 hoặc chọn nhiều, đánh dấu đáp án đúng.')
            ->columns([
                TextColumn::make('number')->label('#')->rowIndex()->color('gray'),
                TextColumn::make('prompt')
                    ->label('Câu hỏi')
                    ->wrap()
                    ->limit(160)
                    ->description(fn (ExamQuestion $record) => ($p = $record->problems()) ? '⚠ ' . implode(' ', $p) : null),
                TextColumn::make('options_summary')
                    ->label('Đáp án')
                    ->state(fn (ExamQuestion $record) => new HtmlString($record->options->map(fn ($o) => $o->is_correct
                        ? '<b style="color:var(--success-600)">✓ ' . e(str($o->label)->limit(50)) . '</b>'
                        : '<span style="opacity:.7">' . e(str($o->label)->limit(50)) . '</span>')->join('<br>')))
                    ->html(),
                TextColumn::make('type')
                    ->label('Dạng')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ExamQuestion::TYPES[$state] ?? $state)
                    ->color('gray'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Thêm câu hỏi')
                    ->icon(Heroicon::Plus)
                    ->color('primary')
                    ->modalWidth('3xl')
                    ->mutateDataUsing(function (array $data) {
                        $data['sort_order'] = (int) $this->exam()->questions()->max('sort_order') + 1;

                        return $data;
                    })
                    ->createAnother(true),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()->modalWidth('3xl'),
                    Action::make('duplicate')
                        ->label('Nhân bản')
                        ->icon(Heroicon::DocumentDuplicate)
                        ->action(function (ExamQuestion $record) {
                            $copy = $record->replicate();
                            $copy->sort_order = (int) $this->exam()->questions()->max('sort_order') + 1;
                            $copy->save();
                            foreach ($record->options as $option) {
                                $copy->options()->create($option->only(['label', 'is_correct', 'sort_order']));
                            }
                            Notification::make()->title('Đã nhân bản câu hỏi')->success()->send();
                        }),
                    DeleteAction::make()->modalDescription('Xoá câu hỏi và các đáp án. Bài làm cũ vẫn giữ kết quả.'),
                ]),
            ]);
    }

    private function exam(): Exam
    {
        /** @var Exam */
        return $this->getOwnerRecord();
    }
}
