<?php

namespace App\Filament\Resources\LearningPaths\Pages;

use App\Enums\ChapterColor;
use App\Enums\UnlockMode;
use App\Filament\Resources\LearningPaths\LearningPathResource;
use App\Filament\Resources\Exams\Pages\ManageExamQuestions;
use App\Filament\Resources\Lessons\LessonResource;
use App\Filament\Resources\Lessons\Schemas\LessonForm;
use App\Models\Exam;
use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\PathChapter;
use App\Models\PathLesson;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Xây dựng lộ trình: chương (tên, màu), bài trong chương (thêm từ thư viện / tạo mới / coaching,
 * quy tắc mở khóa, học thử, kéo thả), bài thi ngang cấp chương (chọn đề từ ngân hàng đề thi)
 * + xem trước bản đồ kiểu Duolingo.
 */
class BuildLearningPath extends Page
{
    use InteractsWithRecord;

    protected static string $resource = LearningPathResource::class;

    protected string $view = 'filament.learning-paths.build';

    public function mount(int | string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->authorizeEdit();
    }

    public function getTitle(): string | Htmlable
    {
        return 'Bài học: ' . $this->path()->name;
    }

    public function getSubheading(): ?string
    {
        return 'Khóa học: ' . $this->path()->course->title;
    }

    public function getBreadcrumb(): ?string
    {
        return 'Bài học';
    }

    // ── Dữ liệu cho view ─────────────────────────────────────

    /** @return Collection<int, PathChapter> */
    public function getChapters(): Collection
    {
        return $this->path()->chapters()
            ->with(['pathLessons.lesson' => fn ($query) => $query->withTrashed(), 'exam' => fn ($query) => $query->withCount('questions')])
            ->get();
    }

    // ── Header ───────────────────────────────────────────────

    protected function getHeaderActions(): array
    {
        return [
            $this->addExamAction(),
            $this->addChapterAction(),
        ];
    }

    // ── Bài thi (ngang cấp chương) ───────────────────────────

    public function addExamAction(): Action
    {
        return Action::make('addExam')
            ->label('Thêm bài thi')
            ->icon(Heroicon::DocumentCheck)
            ->color('gray')
            ->modalHeading('Thêm bài thi')
            ->modalDescription('Bài thi đứng ngang cấp chương (kéo thả để đặt giữa các chương). Học viên làm được khi đã học xong mọi bài đứng trước; đậu khi đúng ≥ ' . Exam::PASS_PERCENT . '%. Không tính vào % hoàn thành khoá, nhưng phải đậu mọi bài thi mới được cấp chứng chỉ.')
            ->modalWidth('lg')
            ->schema(self::examFields())
            ->fillForm(fn () => ['title' => $this->path()->chapters()->where('kind', PathChapter::KIND_EXAM)->exists() ? 'Thi cuối kỳ' : 'Thi giữa kỳ'])
            ->action(function (array $data) {
                $this->authorizeEdit();
                $this->path()->chapters()->create([...$data, 'kind' => PathChapter::KIND_EXAM, 'color' => null]);
            });
    }

    public function editExamAction(): Action
    {
        return Action::make('editExam')
            ->label('Sửa bài thi')
            ->icon(Heroicon::PencilSquare)
            ->modalHeading('Sửa bài thi')
            ->modalWidth('lg')
            ->schema(self::examFields())
            ->fillForm(fn (array $arguments) => $this->chapter($arguments['chapter'])->only(['title', 'exam_id']))
            ->action(function (array $data, array $arguments) {
                $this->authorizeEdit();
                $this->chapter($arguments['chapter'])->update($data);
            });
    }

    public function openExamAction(): Action
    {
        return Action::make('openExam')
            ->label('Xem đề thi')
            ->icon(Heroicon::ArrowTopRightOnSquare)
            ->url(fn (array $arguments) => ($exam = $this->chapter($arguments['chapter'])->exam) ? ManageExamQuestions::getUrl(['record' => $exam]) : null)
            ->openUrlInNewTab()
            ->visible(fn () => auth()->user()->isAdmin());
    }

    // ── Chương ───────────────────────────────────────────────

    public function addChapterAction(): Action
    {
        return Action::make('addChapter')
            ->label('Thêm chương')
            ->icon(Heroicon::Plus)
            ->color('primary')
            ->modalHeading('Thêm chương')
            ->modalWidth('md')
            ->schema(self::chapterFields())
            ->fillForm(fn () => ['color' => ChapterColor::forIndex($this->path()->chapters()->count())->value])
            ->action(function (array $data) {
                $this->authorizeEdit();
                $this->path()->chapters()->create($data);
            });
    }

    public function editChapterAction(): Action
    {
        return Action::make('editChapter')
            ->label('Đổi tên chương')
            ->icon(Heroicon::PencilSquare)
            ->modalHeading('Sửa chương')
            ->modalWidth('md')
            ->schema(self::chapterFields())
            ->fillForm(function (array $arguments) {
                $chapter = $this->chapter($arguments['chapter']);

                return ['title' => $chapter->title, 'color' => $chapter->color?->value];
            })
            ->action(function (array $data, array $arguments) {
                $this->authorizeEdit();
                $this->chapter($arguments['chapter'])->update($data);
            });
    }

    public function deleteChapterAction(): Action
    {
        return Action::make('deleteChapter')
            ->label('Xóa chương')
            ->icon(Heroicon::Trash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments) => isset($arguments['chapter']) && $this->chapter($arguments['chapter'])->isExam() ? 'Gỡ bài thi?' : 'Xóa chương?')
            ->modalDescription(fn (array $arguments) => isset($arguments['chapter']) && $this->chapter($arguments['chapter'])->isExam()
                ? 'Gỡ bài thi khỏi lộ trình (đề vẫn còn trong ngân hàng đề thi, bài làm cũ của học viên vẫn giữ).'
                : 'Các bài trong chương sẽ được gỡ khỏi lộ trình (bài vẫn còn trong thư viện).')
            ->action(function (array $arguments) {
                $this->authorizeEdit();
                $chapter = $this->chapter($arguments['chapter']);
                // Coaching trong chương chỉ sống trong lộ trình → xoá theo
                $coaching = $chapter->pathLessons()->with('lesson')->get()->pluck('lesson')->filter(fn ($l) => $l?->isCoaching());
                $chapter->delete();
                $coaching->each->forceDelete();
                $this->renumberChapters();
            });
    }

    public function setChapterColor(int $chapterId, string $color): void
    {
        $this->authorizeEdit();
        $this->chapter($chapterId)->update(['color' => ChapterColor::from($color)]);
    }

    /** Kéo thả chương. @param array<int|string> $ids */
    public function reorderChapters(array $ids): void
    {
        $this->authorizeEdit();
        $valid = $this->path()->chapters()->pluck('id')->all();

        DB::transaction(function () use ($ids, $valid) {
            foreach (array_values(array_intersect(array_map('intval', $ids), $valid)) as $i => $id) {
                PathChapter::whereKey($id)->update(['sort_order' => $i + 1]);
            }
        });
    }

    // ── Bài trong chương ─────────────────────────────────────

    public function addLessonAction(): Action
    {
        return Action::make('addLesson')
            ->label('Thêm bài')
            ->icon(Heroicon::Plus)
            ->color('primary')
            ->size('sm')
            ->modalHeading('Thêm bài vào chương')
            ->modalWidth('lg')
            ->modalSubmitActionLabel('Thêm')
            ->schema([
                ToggleButtons::make('mode')
                    ->hiddenLabel()
                    ->options([
                        'existing' => 'Chọn bài đã có',
                        'new' => 'Tạo bài mới',
                        'coaching' => 'Coaching',
                    ])
                    ->icons([
                        'existing' => Heroicon::BookOpen,
                        'new' => Heroicon::Sparkles,
                        'coaching' => Heroicon::ChatBubbleLeftRight,
                    ])
                    ->default('existing')
                    ->inline()
                    ->grouped()
                    ->live(),

                // Chọn bài trong thư viện: hiện icon + tên + trạng thái
                Select::make('lesson_id')
                    ->label('Bài học trong thư viện')
                    ->placeholder('Gõ để tìm bài học…')
                    ->searchable()
                    ->allowHtml()
                    ->getSearchResultsUsing(fn (string $search) => $this->lessonOptions($search))
                    ->options(fn () => $this->lessonOptions())
                    ->getOptionLabelUsing(fn ($value) => ($lesson = Lesson::find($value)) ? self::lessonOptionHtml($lesson) : null)
                    ->required(fn (Get $get) => $get('mode') === 'existing')
                    ->visible(fn (Get $get) => $get('mode') === 'existing'),

                // Tạo bài mới vào thư viện
                TextInput::make('title')
                    ->label('Tên bài học')
                    ->required(fn (Get $get) => $get('mode') === 'new')
                    ->maxLength(255)
                    ->visible(fn (Get $get) => $get('mode') === 'new'),
                LessonForm::iconField()
                    ->visible(fn (Get $get) => $get('mode') === 'new'),

                // Coaching: trao đổi 1-1 với giáo viên (đặt lịch theo lịch rảnh — làm sau), không có nội dung
                TextInput::make('coaching_title')
                    ->label('Tên bài coaching')
                    ->default(Lesson::COACHING_DEFAULT_TITLE)
                    ->required(fn (Get $get) => $get('mode') === 'coaching')
                    ->maxLength(255)
                    ->helperText('Học viên đặt lịch trao đổi 1-1 với giáo viên. Bài coaching không có nội dung, không chặn bài sau và không tính vào % hoàn thành.')
                    ->visible(fn (Get $get) => $get('mode') === 'coaching'),

                Select::make('unlock_mode')
                    ->label('Mở khóa')
                    ->options(UnlockMode::class)
                    ->default(UnlockMode::AfterPrevious)
                    ->required(),
                Toggle::make('is_preview')
                    ->label('Cho học thử (xem không cần mua)')
                    ->visible(fn (Get $get) => $get('mode') !== 'coaching'),
            ])
            ->action(function (array $data, array $arguments) {
                $this->authorizeEdit();
                $chapter = $this->chapter($arguments['chapter']);

                if ($data['mode'] === 'coaching') {
                    $lesson = Lesson::create([
                        'kind' => Lesson::KIND_COACHING,
                        'title' => $data['coaching_title'] ?? null,
                    ]);
                    $data['is_preview'] = false;
                } elseif ($data['mode'] === 'new') {
                    $lesson = Lesson::create([
                        'title' => $data['title'],
                        'icon' => $data['icon'] ?? null,
                    ]);
                } else {
                    $lesson = Lesson::query()->library()->visibleTo(auth()->user())->findOrFail($data['lesson_id']);
                }

                if ($this->path()->pathLessons()->where('lesson_id', $lesson->id)->exists()) {
                    Notification::make()->title('Bài này đã có trong lộ trình')->warning()->send();

                    return;
                }

                $chapter->pathLessons()->create([
                    'lesson_id' => $lesson->id,
                    'unlock_mode' => $data['unlock_mode'],
                    'is_preview' => (bool) ($data['is_preview'] ?? false),
                    'sort_order' => (int) $chapter->pathLessons()->max('sort_order') + 1,
                ]);
            });
    }

    public function editLessonInfoAction(): Action
    {
        return Action::make('editLessonInfo')
            ->label('Sửa tên & icon')
            ->icon(Heroicon::PencilSquare)
            ->modalHeading('Sửa tên & icon')
            ->modalDescription(fn (array $arguments) => ($lesson = $this->argLesson($arguments)) ? LessonResource::usageWarning($lesson) : null)
            ->modalWidth('lg')
            ->schema(LessonForm::fields())
            ->fillForm(fn (array $arguments) => $this->pathLesson($arguments['item'])->lesson->only(['title', 'icon', 'summary']))
            ->visible(fn (array $arguments) => $this->canEditLesson($arguments) && ! $this->argLesson($arguments)->isCoaching())
            ->action(function (array $data, array $arguments) {
                $lesson = $this->pathLesson($arguments['item'])->lesson;
                abort_unless(auth()->user()->can('update', $lesson), 403);
                $lesson->update($data);
            });
    }

    public function editCoachingAction(): Action
    {
        return Action::make('editCoaching')
            ->label('Đổi tên')
            ->icon(Heroicon::PencilSquare)
            ->modalHeading('Đổi tên bài coaching')
            ->modalWidth('md')
            ->schema([
                TextInput::make('title')->label('Tên bài coaching')->required()->maxLength(255),
            ])
            ->fillForm(fn (array $arguments) => ['title' => $this->pathLesson($arguments['item'])->lesson->title])
            ->visible(fn (array $arguments) => (bool) $this->argLesson($arguments)?->isCoaching())
            ->action(function (array $data, array $arguments) {
                $this->authorizeEdit();
                $lesson = $this->pathLesson($arguments['item'])->lesson;
                abort_unless($lesson->isCoaching(), 404);
                $lesson->update(['title' => $data['title']]);
            });
    }

    public function editContentAction(): Action
    {
        return Action::make('editContent')
            ->label('Sửa nội dung')
            ->icon(Heroicon::PencilSquare)
            ->link()
            ->size('sm')
            ->url(fn (array $arguments) => ($lesson = $this->argLesson($arguments)) ? LessonResource::getUrl('content', ['record' => $lesson]) : null)
            ->visible(fn (array $arguments) => $this->canEditLesson($arguments) && ! $this->argLesson($arguments)->isCoaching());
    }

    public function removeLessonAction(): Action
    {
        return Action::make('removeLesson')
            ->label('Gỡ khỏi chương')
            ->icon(Heroicon::XMark)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Gỡ bài khỏi lộ trình?')
            ->modalDescription(fn (array $arguments) => $this->argLesson($arguments)?->isCoaching()
                ? 'Bài coaching sẽ bị xoá.'
                : 'Bài vẫn còn trong thư viện bài học.')
            ->action(function (array $arguments) {
                $this->authorizeEdit();
                $item = $this->pathLesson($arguments['item']);
                $item->delete();
                // Coaching chỉ sống trong lộ trình
                if ($item->lesson?->isCoaching()) {
                    $item->lesson->forceDelete();
                }
            });
    }

    public function moveLessonUpAction(): Action
    {
        return Action::make('moveLessonUp')
            ->label('Chuyển lên')
            ->icon(Heroicon::ArrowUp)
            ->action(fn (array $arguments) => $this->shiftLesson((int) $arguments['item'], -1));
    }

    public function moveLessonDownAction(): Action
    {
        return Action::make('moveLessonDown')
            ->label('Chuyển xuống')
            ->icon(Heroicon::ArrowDown)
            ->action(fn (array $arguments) => $this->shiftLesson((int) $arguments['item'], 1));
    }

    /**
     * Đổi chỗ bài với bài liền trước / liền sau trên bản đồ.
     * Ở đầu / cuối chương thì chuyển sang cuối chương trước / đầu chương sau.
     */
    private function shiftLesson(int $itemId, int $direction): void
    {
        $this->authorizeEdit();

        $chapters = $this->path()->chapters()->where('kind', PathChapter::KIND_CHAPTER)->with('pathLessons')->get()->values();
        $chapterIndex = $chapters->search(fn (PathChapter $c) => $c->pathLessons->contains('id', $itemId));

        if ($chapterIndex === false) {
            return;
        }

        $ids = $chapters[$chapterIndex]->pathLessons->pluck('id')->all();
        $pos = array_search($itemId, $ids, true);
        $target = $pos + $direction;

        if ($target >= 0 && $target < count($ids)) {
            // Trong cùng chương
            [$ids[$pos], $ids[$target]] = [$ids[$target], $ids[$pos]];
            $this->moveLessons($chapters[$chapterIndex]->id, $ids);

            return;
        }

        // Sang chương bên cạnh
        $neighbour = $chapters[$chapterIndex + $direction] ?? null;

        if (! $neighbour) {
            return;
        }

        array_splice($ids, $pos, 1);
        $neighbourIds = $neighbour->pathLessons->pluck('id')->all();
        if ($direction < 0) {
            $neighbourIds[] = $itemId;              // lên: vào cuối chương trước
        } else {
            array_unshift($neighbourIds, $itemId); // xuống: vào đầu chương sau
        }

        $this->moveLessons($neighbour->id, $neighbourIds, $chapters[$chapterIndex]->id, $ids);
    }

    public function toggleUnlock(int $itemId): void
    {
        $this->authorizeEdit();
        $item = $this->pathLesson($itemId);
        $item->update([
            'unlock_mode' => $item->unlock_mode === UnlockMode::Always ? UnlockMode::AfterPrevious : UnlockMode::Always,
        ]);
    }

    public function togglePreview(int $itemId): void
    {
        $this->authorizeEdit();
        $item = $this->pathLesson($itemId);
        if ($item->lesson?->isCoaching()) {
            return; // coaching không có học thử
        }
        $item->update(['is_preview' => ! $item->is_preview]);
    }

    /**
     * Kéo thả bài: trong một chương hoặc sang chương khác.
     *
     * @param  array<int|string>  $toIds  thứ tự mới của chương đích
     * @param  array<int|string>  $fromIds  thứ tự mới của chương nguồn (khi kéo sang chương khác)
     */
    public function moveLessons(int | string $toChapterId, array $toIds, int | string | null $fromChapterId = null, array $fromIds = []): void
    {
        $this->authorizeEdit();

        $lists = [(int) $toChapterId => $toIds];
        if ($fromChapterId !== null && (int) $fromChapterId !== (int) $toChapterId) {
            $lists[(int) $fromChapterId] = $fromIds;
        }

        $pathItemIds = $this->path()->pathLessons()->pluck('id')->all();

        DB::transaction(function () use ($lists, $pathItemIds) {
            foreach ($lists as $chapterId => $ids) {
                $chapter = $this->chapter($chapterId);
                if ($chapter->isExam()) {
                    continue; // bài thi không chứa bài
                }

                foreach (array_values(array_intersect(array_map('intval', $ids), $pathItemIds)) as $i => $id) {
                    PathLesson::whereKey($id)->update([
                        'path_chapter_id' => $chapter->id,
                        'sort_order' => $i + 1,
                    ]);
                }
            }
        });
    }

    // ── Hỗ trợ ───────────────────────────────────────────────

    private function path(): LearningPath
    {
        /** @var LearningPath */
        return $this->getRecord();
    }

    private function chapter(int | string $id): PathChapter
    {
        return $this->path()->chapters()->findOrFail($id);
    }

    private function pathLesson(int | string $id): PathLesson
    {
        return $this->path()->pathLessons()->with('lesson')->findOrFail($id);
    }

    /** Bài học ứng với tham số 'item' của action (null khi action chưa được gọi kèm tham số). */
    private function argLesson(array $arguments): ?Lesson
    {
        return isset($arguments['item']) ? $this->pathLesson($arguments['item'])->lesson : null;
    }

    private function canEditLesson(array $arguments): bool
    {
        $lesson = $this->argLesson($arguments);

        return $lesson !== null && ! $lesson->trashed() && auth()->user()->can('update', $lesson);
    }

    private function authorizeEdit(): void
    {
        abort_unless(auth()->user()->can('update', $this->path()), 403);
    }

    private function renumberChapters(): void
    {
        $this->path()->chapters()->get()->each(fn (PathChapter $c, int $i) => $c->update(['sort_order' => $i + 1]));
    }

    /** @return array<\Filament\Schemas\Components\Component> */
    private static function examFields(): array
    {
        return [
            TextInput::make('title')
                ->label('Tên hiển thị trên lộ trình')
                ->placeholder('VD: Thi giữa kỳ')
                ->required()
                ->maxLength(255),
            Select::make('exam_id')
                ->label('Đề thi')
                ->options(fn () => Exam::query()->withCount('questions')->orderBy('title')->get()
                    ->mapWithKeys(fn (Exam $exam) => [$exam->id => $exam->title . ' · ' . $exam->questionsPerAttempt((int) $exam->questions_count) . ' câu'
                        . ($exam->time_limit_minutes ? ', ' . $exam->time_limit_minutes . ' phút' : '')])
                    ->all())
                ->searchable()
                ->required()
                ->helperText('Chọn từ Đào tạo › Ngân hàng đề thi.'),
        ];
    }

    /** @return array<\Filament\Schemas\Components\Component> */
    private static function chapterFields(): array
    {
        return [
            TextInput::make('title')
                ->label('Tên chương')
                ->required()
                ->maxLength(255),
            Select::make('color')
                ->label('Màu chương')
                ->allowHtml()
                ->options(collect(ChapterColor::cases())->mapWithKeys(fn (ChapterColor $c) => [
                    $c->value => '<span style="display:inline-flex;align-items:center;gap:.5rem">'
                        . '<span style="width:1rem;height:1rem;border-radius:9999px;background:' . $c->hex() . '"></span>'
                        . e($c->getLabel()) . '</span>',
                ])->all())
                ->required(),
        ];
    }

    /** Bài trong thư viện (người dùng được thấy), chưa có trong lộ trình. */
    private function lessonOptions(?string $search = null): array
    {
        return Lesson::query()
            ->library()
            ->visibleTo(auth()->user())
            ->whereNotIn('id', $this->path()->pathLessons()->select('lesson_id'))
            ->when(filled($search), fn ($query) => $query->where('title', 'like', "%{$search}%"))
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Lesson $lesson) => [$lesson->id => self::lessonOptionHtml($lesson)])
            ->all();
    }

    private static function lessonOptionHtml(Lesson $lesson): string
    {
        $icon = $lesson->iconUrl()
            ? '<img src="' . e($lesson->iconUrl()) . '" style="width:1.25rem;height:1.25rem" alt="">'
            : '';
        $status = $lesson->contentStatus();

        return '<span style="display:inline-flex;align-items:center;gap:.5rem">'
            . '<span style="display:inline-flex;align-items:center;justify-content:center;width:1.75rem;height:1.75rem;border-radius:9999px;background:#3E4651">' . $icon . '</span>'
            . '<span>' . e($lesson->title) . '</span>'
            . '<span style="font-size:.75rem;opacity:.6">· ' . e($status->getLabel()) . '</span>'
            . '</span>';
    }
}