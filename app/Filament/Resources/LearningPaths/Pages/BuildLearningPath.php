<?php

namespace App\Filament\Resources\LearningPaths\Pages;

use App\Enums\ChapterColor;
use App\Enums\UnlockMode;
use App\Filament\Resources\LearningPaths\LearningPathResource;
use App\Filament\Resources\Lessons\LessonResource;
use App\Filament\Resources\Lessons\Schemas\LessonForm;
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
 * Xây dựng lộ trình: chương (tên, màu), bài trong chương (thêm từ thư viện / tạo mới,
 * quy tắc mở khóa, học thử, kéo thả) + xem trước bản đồ kiểu Duolingo.
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
            ->with(['pathLessons.lesson' => fn ($query) => $query->withTrashed()])
            ->get();
    }

    // ── Header ───────────────────────────────────────────────

    protected function getHeaderActions(): array
    {
        return [
            $this->addChapterAction(),
        ];
    }

    // ── Chương ───────────────────────────────────────────────

    public function addChapterAction(): Action
    {
        return Action::make('addChapter')
            ->label('Thêm chương')
            ->icon(Heroicon::Plus)
            ->color('success')
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
            ->modalHeading('Xóa chương?')
            ->modalDescription('Các bài trong chương sẽ được gỡ khỏi lộ trình (bài vẫn còn trong thư viện).')
            ->action(function (array $arguments) {
                $this->authorizeEdit();
                $this->chapter($arguments['chapter'])->delete();
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
            ->color('success')
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
                    ])
                    ->icons([
                        'existing' => Heroicon::BookOpen,
                        'new' => Heroicon::Sparkles,
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

                Select::make('unlock_mode')
                    ->label('Mở khóa')
                    ->options(UnlockMode::class)
                    ->default(UnlockMode::AfterPrevious)
                    ->required(),
                Toggle::make('is_preview')
                    ->label('Cho học thử (xem không cần mua)'),
            ])
            ->action(function (array $data, array $arguments) {
                $this->authorizeEdit();
                $chapter = $this->chapter($arguments['chapter']);

                if ($data['mode'] === 'new') {
                    $lesson = Lesson::create([
                        'title' => $data['title'],
                        'icon' => $data['icon'] ?? null,
                    ]);
                } else {
                    $lesson = Lesson::query()->visibleTo(auth()->user())->findOrFail($data['lesson_id']);
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
            ->visible(fn (array $arguments) => $this->canEditLesson($arguments))
            ->action(function (array $data, array $arguments) {
                $lesson = $this->pathLesson($arguments['item'])->lesson;
                abort_unless(auth()->user()->can('update', $lesson), 403);
                $lesson->update($data);
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
            ->visible(fn (array $arguments) => $this->canEditLesson($arguments));
    }

    public function removeLessonAction(): Action
    {
        return Action::make('removeLesson')
            ->label('Gỡ khỏi chương')
            ->icon(Heroicon::XMark)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Gỡ bài khỏi lộ trình?')
            ->modalDescription('Bài vẫn còn trong thư viện bài học.')
            ->action(function (array $arguments) {
                $this->authorizeEdit();
                $this->pathLesson($arguments['item'])->delete();
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

        $chapters = $this->path()->chapters()->with('pathLessons')->get();
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