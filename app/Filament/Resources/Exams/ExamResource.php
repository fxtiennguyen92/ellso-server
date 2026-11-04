<?php

namespace App\Filament\Resources\Exams;

use App\Filament\Resources\Exams\Pages\CreateExam;
use App\Filament\Resources\Exams\Pages\EditExam;
use App\Filament\Resources\Exams\Pages\ListExams;
use App\Filament\Resources\Exams\Pages\ManageExamAttempts;
use App\Filament\Resources\Exams\Pages\ManageExamQuestions;
use App\Models\Exam;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Resources\Pages\Page;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Đào tạo › Ngân hàng đề thi: đề trắc nghiệm có đáp án đúng, dùng chung cho nhiều lộ trình.
 * Đặt vào lộ trình ở trang Bài học của lộ trình (nút "Thêm bài thi", ngang cấp chương).
 * Đậu khi đúng ≥ 70% (chấm trên 100). Rút ngẫu nhiên câu, đảo đáp án, giới hạn thời gian tuỳ chọn.
 */
class ExamResource extends Resource
{
    protected static ?string $model = Exam::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static string | UnitEnum | null $navigationGroup = 'Đào tạo';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Ngân hàng đề thi';

    protected static ?string $modelLabel = 'đề thi';

    protected static ?string $pluralModelLabel = 'Ngân hàng đề thi';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columnSpanFull()
                ->schema([
                    TextInput::make('title')
                        ->label('Tên đề thi')
                        ->placeholder('VD: Thi cuối kỳ — Tạo web bằng AI')
                        ->required()
                        ->maxLength(150),
                    Textarea::make('intro')
                        ->label('Lời dặn trước khi làm bài')
                        ->rows(3)
                        ->helperText('Hiện trên app trước khi học viên bắt đầu. VD: phạm vi kiến thức, số câu, thời gian.'),
                    TextInput::make('question_count')
                        ->label('Số câu mỗi lần làm')
                        ->integer()
                        ->minValue(1)
                        ->maxValue(500)
                        ->suffix('câu')
                        ->placeholder('Tất cả câu')
                        ->helperText(function (?Exam $record) {
                            $total = $record?->questions()->count();

                            return 'Để trống = làm tất cả câu, theo thứ tự soạn. Nhập số → mỗi lần làm rút ngẫu nhiên từng ấy câu'
                                . ($total ? " trong {$total} câu đã soạn" : '') . '. Làm lại → bộ câu mới.';
                        }),
                    Toggle::make('shuffle_options')
                        ->label('Đảo thứ tự đáp án')
                        ->default(true),
                    Toggle::make('has_time_limit')
                        ->label('Giới hạn thời gian')
                        ->dehydrated(false)
                        ->afterStateHydrated(fn (Toggle $component, ?Exam $record) => $component->state($record?->time_limit_minutes !== null))
                        ->live(),
                    TextInput::make('time_limit_minutes')
                        ->label('Thời gian làm bài')
                        ->integer()
                        ->minValue(1)
                        ->maxValue(600)
                        ->suffix('phút')
                        ->required(fn (Get $get) => (bool) $get('has_time_limit'))
                        ->visible(fn (Get $get) => (bool) $get('has_time_limit'))
                        ->helperText('Hết giờ, bài được tự nộp với các câu đã trả lời.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->withCount(['questions', 'pathChapters', 'attempts' => fn ($q) => $q->whereNotNull('submitted_at')]))
            ->recordUrl(fn (Exam $record) => ManageExamQuestions::getUrl(['record' => $record]))
            ->columns([
                TextColumn::make('title')
                    ->label('Đề thi')
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (Exam $record) => ($p = $record->problems()) ? '⚠ ' . $p[0] : null),
                TextColumn::make('questions_count')
                    ->label('Câu hỏi')
                    ->alignCenter()
                    ->formatStateUsing(fn (int $state, Exam $record) => $record->question_count && $record->question_count < $state
                        ? $record->question_count . ' / ' . $state
                        : (string) $state),
                TextColumn::make('time_limit_minutes')
                    ->label('Thời gian')
                    ->alignCenter()
                    ->formatStateUsing(fn (?int $state) => $state ? $state . ' phút' : 'Không giới hạn')
                    ->placeholder('Không giới hạn'),
                TextColumn::make('path_chapters_count')
                    ->label('Dùng ở lộ trình')
                    ->alignCenter()
                    ->tooltip(fn (Exam $record) => $record->path_chapters_count
                        ? $record->pathChapters()->with('learningPath.course')->get()
                            ->map(fn ($c) => $c->learningPath?->course?->title . ' › ' . $c->learningPath?->name)->unique()->join(', ')
                        : null),
                TextColumn::make('attempts_count')->label('Bài làm')->numeric()->alignCenter(),
                TextColumn::make('updated_at')
                    ->label('Cập nhật')
                    ->dateTime('H:i d/m/Y', 'Asia/Ho_Chi_Minh')
                    ->color('gray'),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()->label('Sửa thông tin'),
                    Action::make('duplicate')
                        ->label('Nhân bản')
                        ->icon(Heroicon::DocumentDuplicate)
                        ->authorize('replicate')
                        ->action(function (Exam $record, $livewire) {
                            $copy = DB::transaction(function () use ($record) {
                                $copy = $record->replicate(['created_by_id']);
                                $copy->title = $record->title . ' (bản sao)';
                                $copy->save();
                                foreach ($record->questions()->with('options')->get() as $question) {
                                    $newQuestion = $copy->questions()->create($question->only(['type', 'prompt', 'explanation', 'sort_order']));
                                    foreach ($question->options as $option) {
                                        $newQuestion->options()->create($option->only(['label', 'is_correct', 'sort_order']));
                                    }
                                }

                                return $copy;
                            });
                            Notification::make()->title('Đã nhân bản đề thi')->success()->send();
                            $livewire->redirect(ManageExamQuestions::getUrl(['record' => $copy]));
                        }),
                    DeleteAction::make()
                        ->modalDescription('Xoá đề thi cùng toàn bộ câu hỏi.'),
                ]),
            ]);
    }

    public static function getRecordSubNavigation(Page $page): array
    {
        return $page->generateNavigationItems([
            ManageExamQuestions::class,
            ManageExamAttempts::class,
            EditExam::class,
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExams::route('/'),
            'create' => CreateExam::route('/create'),
            'edit' => EditExam::route('/{record}/edit'),
            'questions' => ManageExamQuestions::route('/{record}/questions'),
            'attempts' => ManageExamAttempts::route('/{record}/attempts'),
        ];
    }

    /** Bỏ thời gian khi tắt "Giới hạn thời gian". */
    public static function cleanData(array $data, bool $hasTimeLimit): array
    {
        if (! $hasTimeLimit) {
            $data['time_limit_minutes'] = null;
        }

        return $data;
    }
}
