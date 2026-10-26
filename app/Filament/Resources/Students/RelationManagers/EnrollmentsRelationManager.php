<?php

namespace App\Filament\Resources\Students\RelationManagers;

use App\Enums\EnrollmentSource;
use App\Filament\Resources\Students\EnrollCourseAction;
use App\Models\CourseEnrollment;
use App\Models\LearningPath;
use App\Models\LessonQuizAnswer;
use App\Services\LearningProgress;
use App\Support\LessonContent;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/** Học bạ › Khoá học: khoá đã / đang học, % hoàn thành, mở khoá mới, đổi lộ trình, xem tiến độ từng bài. */
class EnrollmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'enrollments';

    protected static ?string $title = 'Khoá học';

    protected static string | \BackedEnum | null $icon = Heroicon::OutlinedBookOpen;

    /** Học bạ là trang "xem" nhưng vẫn cho thu hồi khoá học. */
    public function isReadOnly(): bool
    {
        return false;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->enrollments()->count();
    }

    public function table(Table $table): Table
    {
        $progress = app(LearningProgress::class);

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['course', 'learningPath', 'user', 'grantedBy']))
            ->defaultSort('enrolled_at', 'desc')
            ->emptyStateHeading('Chưa học khoá nào')
            ->emptyStateDescription('Bấm "Mở khoá học" để cho học viên học một khoá.')
            ->columns([
                TextColumn::make('course.title')
                    ->label('Khoá học')
                    ->weight('medium')
                    ->description(fn (CourseEnrollment $record) => $record->learningPath
                        ? 'Lộ trình: ' . $record->learningPath->name
                        : 'Chưa chọn lộ trình (học viên trả lời câu hỏi trên app)')
                    ->wrap(),
                TextColumn::make('progress')
                    ->label('Tiến độ')
                    ->state(fn (CourseEnrollment $record) => self::progressBar($progress->summary($record)))
                    ->html(),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->state(fn (CourseEnrollment $record) => match (true) {
                        $record->completed_at !== null => 'Hoàn thành',
                        $record->learningPath === null => 'Chờ chọn lộ trình',
                        $record->last_activity_at === null => 'Chưa bắt đầu',
                        default => 'Đang học',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'Hoàn thành' => 'success',
                        'Đang học' => 'info',
                        default => 'gray',
                    })
                    ->description(fn (CourseEnrollment $record) => $record->completed_at?->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y')),
                TextColumn::make('last_activity_at')
                    ->label('Học gần nhất')
                    ->since()
                    ->dateTimeTooltip('H:i d/m/Y', 'Asia/Ho_Chi_Minh')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('source')
                    ->label('Nguồn')
                    ->badge()
                    ->description(fn (CourseEnrollment $record) => $record->enrolled_at->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y')
                        . ($record->grantedBy ? ' · ' . $record->grantedBy->name : ''))
                    ->tooltip(fn (CourseEnrollment $record) => $record->note),
            ])
            ->filters([
                SelectFilter::make('source')->label('Nguồn')->options(EnrollmentSource::class),
            ])
            ->headerActions([
                EnrollCourseAction::make(fn () => $this->getOwnerRecord()),
            ])
            ->recordActions([
                Action::make('detail')
                    ->label('Tiến độ')
                    ->icon(Heroicon::ChartBar)
                    ->color('gray')
                    ->modalHeading(fn (CourseEnrollment $record) => $record->course->title)
                    ->modalDescription(fn (CourseEnrollment $record) => $record->learningPath ? 'Lộ trình: ' . $record->learningPath->name : 'Chưa chọn lộ trình')
                    ->modalWidth('3xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Đóng')
                    ->modalContent(fn (CourseEnrollment $record) => view('filament.students.enrollment-progress', [
                        'rows' => $this->lessonRows($record),
                        'summary' => app(LearningProgress::class)->summary($record),
                    ])),
                ActionGroup::make([
                    Action::make('changePath')
                        ->label('Đổi lộ trình')
                        ->icon(Heroicon::ArrowsRightLeft)
                        ->schema([
                            Select::make('learning_path_id')
                                ->label('Lộ trình')
                                ->options(fn (CourseEnrollment $record) => LearningPath::where('course_id', $record->course_id)->orderBy('sort_order')->pluck('name', 'id'))
                                ->placeholder('Bỏ lộ trình — học viên chọn lại trên app')
                                ->default(fn (CourseEnrollment $record) => $record->learning_path_id),
                        ])
                        ->modalDescription('Tiến độ tính theo bài: bài trùng giữa các lộ trình vẫn giữ trạng thái đã học.')
                        ->action(function (CourseEnrollment $record, array $data) {
                            $record->update(['learning_path_id' => $data['learning_path_id'] ?: null]);
                            app(LearningProgress::class)->refreshCourseCompletion($record->refresh());
                            Notification::make()->title('Đã cập nhật lộ trình')->success()->send();
                        }),
                    DeleteAction::make()
                        ->label('Thu hồi khoá học')
                        ->modalHeading('Thu hồi khoá học?')
                        ->modalDescription('Học viên không học tiếp khoá này trên app nữa. Tiến độ các bài vẫn được giữ — mở lại khoá thì học tiếp.'),
                ]),
            ]);
    }

    /** @param array{total:int, completed:int, percent:int|null} $s */
    public static function progressBar(array $s): HtmlString
    {
        if ($s['percent'] === null) {
            return new HtmlString('<span style="color:var(--gray-400)">—</span>');
        }
        $color = $s['percent'] >= 100 ? 'var(--success-500)' : 'var(--primary-500)';

        return new HtmlString(
            '<div style="min-width:150px">'
            . '<div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px"><b>' . $s['percent'] . '%</b>'
            . '<span style="color:var(--gray-500)">' . $s['completed'] . '/' . $s['total'] . ' bài</span></div>'
            . '<div style="height:6px;border-radius:99px;background:color-mix(in oklab, var(--gray-500) 18%, transparent)">'
            . '<div style="height:6px;border-radius:99px;width:' . $s['percent'] . '%;background:' . $color . '"></div></div></div>'
        );
    }

    /** Từng bài trong lộ trình: trạng thái, thời điểm hoàn thành, câu hỏi đúng / tổng, số lần trả lời. */
    private function lessonRows(CourseEnrollment $enrollment): array
    {
        if (! $enrollment->learningPath) {
            return [];
        }

        $user = $enrollment->user;
        $states = app(LearningProgress::class)->itemStates($user, $enrollment->learningPath);
        $answers = LessonQuizAnswer::query()
            ->where('user_id', $user->id)
            ->whereIn('lesson_id', collect($states)->pluck('item.lesson_id'))
            ->get()
            ->groupBy('lesson_id');

        return array_map(function (array $row) use ($answers) {
            $lesson = $row['item']->lesson;
            $quizzes = array_keys(LessonContent::quizzes($lesson->content_published));
            $lessonAnswers = $answers->get($lesson->id, collect());

            return [
                'chapter' => $row['item']->chapter?->title,
                'title' => $lesson->title,
                'state' => $row['state'],
                'completed_at' => $row['progress']?->completed_at?->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y'),
                'viewed_at' => $row['progress']?->last_viewed_at?->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y'),
                'quiz_total' => count($quizzes),
                'quiz_correct' => count(array_intersect($quizzes, $lessonAnswers->where('is_correct', true)->pluck('block_id')->unique()->all())),
                'attempts' => $lessonAnswers->count(),
            ];
        }, $states);
    }
}
