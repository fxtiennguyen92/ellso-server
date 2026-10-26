<?php

namespace App\Services;

use App\Enums\EnrollmentSource;
use App\Enums\UnlockMode;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\LessonQuizAnswer;
use App\Models\PathLesson;
use App\Models\User;
use App\Support\LessonContent;
use Illuminate\Support\Collection;

/**
 * Tiến độ học: ghi danh, trạng thái từng bài trong lộ trình, chấm câu hỏi, hoàn thành bài / khoá.
 *
 *  - Hoàn thành bài = đã đọc đến cuối (read_at) + mỗi câu hỏi trong bản đã xuất bản có ít nhất một lần trả lời đúng.
 *    Bài không có câu hỏi: đọc hết là xong. Trả lời sai được làm lại không giới hạn.
 *  - Tiến độ lưu theo bài (không theo lộ trình) → đổi lộ trình, bài trùng vẫn tính đã học.
 *  - Trạng thái bài trong lộ trình: completed | available | locked. "Mở khi xong bài trước" khoá đến khi bài liền trước
 *    hoàn thành; "Luôn mở" luôn học được. Chỉ tính bài đã xuất bản (bài chưa xuất bản ẩn trên app).
 *  - % hoàn thành khoá = bài hoàn thành / tổng bài (đã xuất bản) của lộ trình đang học.
 */
class LearningProgress
{
    // ── Ghi danh ─────────────────────────────────────────────

    /** Mở khoá cho học viên (đã có thì cập nhật lộ trình nếu truyền). */
    public function enroll(User $user, Course $course, EnrollmentSource $source, ?LearningPath $path = null, ?User $by = null, ?string $note = null): CourseEnrollment
    {
        $enrollment = CourseEnrollment::query()->firstOrNew(['user_id' => $user->id, 'course_id' => $course->id]);

        if (! $enrollment->exists) {
            $enrollment->fill(['source' => $source, 'granted_by_id' => $by?->id, 'note' => $note, 'enrolled_at' => now()]);
        }
        if ($path) {
            $enrollment->learning_path_id = $path->id;
        }
        // Khoá học chung: chỉ có 1 lộ trình → gán luôn
        $enrollment->learning_path_id ??= $course->learningPaths()->count() === 1 ? $course->defaultPath?->id : null;
        $enrollment->save();
        $this->refreshCourseCompletion($enrollment);

        return $enrollment;
    }

    // ── Lộ trình ─────────────────────────────────────────────

    /** Bài (đã xuất bản) của lộ trình theo thứ tự chương → bài. @return Collection<int, PathLesson> */
    public function pathItems(LearningPath $path): Collection
    {
        return PathLesson::query()
            ->select('path_lessons.*')
            ->join('path_chapters', 'path_chapters.id', '=', 'path_lessons.path_chapter_id')
            ->where('path_lessons.learning_path_id', $path->id)
            ->orderBy('path_chapters.sort_order')
            ->orderBy('path_chapters.id')
            ->orderBy('path_lessons.sort_order')
            ->orderBy('path_lessons.id')
            ->with(['lesson', 'chapter'])
            ->get()
            ->filter(fn (PathLesson $item) => $item->lesson?->isPublished())
            ->values();
    }

    /** @return array<int, LessonProgress> lesson_id => tiến độ */
    public function progressFor(User $user, iterable $lessonIds): array
    {
        return LessonProgress::query()
            ->where('user_id', $user->id)
            ->whereIn('lesson_id', collect($lessonIds)->all())
            ->get()
            ->keyBy('lesson_id')
            ->all();
    }

    /**
     * Trạng thái từng bài trong lộ trình.
     *
     * @return list<array{item: PathLesson, state: string, progress: ?LessonProgress}>
     */
    public function itemStates(User $user, LearningPath $path): array
    {
        $items = $this->pathItems($path);
        $progress = $this->progressFor($user, $items->pluck('lesson_id'));
        $previousDone = true;
        $out = [];

        foreach ($items as $item) {
            $p = $progress[$item->lesson_id] ?? null;
            $done = $p?->completed_at !== null;
            $state = match (true) {
                $done => 'completed',
                $item->unlock_mode === UnlockMode::Always || $previousDone => 'available',
                default => 'locked',
            };
            $out[] = ['item' => $item, 'state' => $state, 'progress' => $p];
            $previousDone = $done;
        }

        return $out;
    }

    /**
     * Tóm tắt tiến độ một ghi danh.
     *
     * @return array{total: int, completed: int, percent: int|null, next: ?PathLesson}
     */
    public function summary(CourseEnrollment $enrollment): array
    {
        $path = $enrollment->learningPath;
        if (! $path) {
            return ['total' => 0, 'completed' => 0, 'percent' => null, 'next' => null];
        }

        $states = $this->itemStates($enrollment->user, $path);
        $total = count($states);
        $completed = count(array_filter($states, fn ($s) => $s['state'] === 'completed'));
        $next = collect($states)->first(fn ($s) => $s['state'] === 'available')['item'] ?? null;

        return [
            'total' => $total,
            'completed' => $completed,
            'percent' => $total ? (int) floor($completed / $total * 100) : 0,
            'next' => $next,
        ];
    }

    // ── Truy cập bài ─────────────────────────────────────────

    /**
     * Học viên mở được bài này không: bài thuộc lộ trình đang học của một khoá đã ghi danh và không bị khoá,
     * hoặc là bài học thử của một khoá đã xuất bản. Trả về ghi danh liên quan (null nếu là bài học thử).
     *
     * @return array{allowed: bool, enrollment: ?CourseEnrollment, reason: ?string}
     */
    public function access(User $user, Lesson $lesson): array
    {
        if (! $lesson->isPublished()) {
            return ['allowed' => false, 'enrollment' => null, 'reason' => 'not_found'];
        }

        $enrollments = CourseEnrollment::query()
            ->where('user_id', $user->id)
            ->whereNotNull('learning_path_id')
            ->whereHas('learningPath.pathLessons', fn ($q) => $q->where('lesson_id', $lesson->id))
            ->with(['learningPath', 'user'])
            ->get();

        $locked = false;
        foreach ($enrollments as $enrollment) {
            $state = collect($this->itemStates($user, $enrollment->learningPath))
                ->first(fn ($s) => $s['item']->lesson_id === $lesson->id)['state'] ?? 'locked';
            if ($state !== 'locked') {
                return ['allowed' => true, 'enrollment' => $enrollment, 'reason' => null];
            }
            $locked = true;
        }

        $preview = PathLesson::query()
            ->where('lesson_id', $lesson->id)
            ->where('is_preview', true)
            ->whereHas('learningPath.course', fn ($q) => $q->where('status', 'published'))
            ->exists();

        return $preview
            ? ['allowed' => true, 'enrollment' => null, 'reason' => null]
            : ['allowed' => false, 'enrollment' => null, 'reason' => $locked ? 'locked' : 'not_enrolled'];
    }

    // ── Ghi tiến độ ──────────────────────────────────────────

    public function viewed(User $user, Lesson $lesson, ?CourseEnrollment $enrollment): LessonProgress
    {
        $progress = LessonProgress::query()->firstOrNew(['user_id' => $user->id, 'lesson_id' => $lesson->id]);
        $progress->first_viewed_at ??= now();
        $progress->last_viewed_at = now();
        $progress->save();
        $enrollment?->forceFill(['last_activity_at' => now()])->save();

        return $progress;
    }

    /** Học viên đã đọc đến cuối bài. */
    public function markRead(User $user, Lesson $lesson, ?CourseEnrollment $enrollment): LessonProgress
    {
        $progress = $this->viewed($user, $lesson, $enrollment);
        $progress->read_at ??= now();
        $progress->save();

        return $this->refreshLessonCompletion($user, $lesson, $progress);
    }

    /**
     * Chấm một câu hỏi. Không trả đáp án đúng (để học viên tự làm lại); trả giải thích sau khi trả lời.
     *
     * @param  list<string>  $optionIds
     * @return array{correct: bool, explanation: ?string, progress: LessonProgress}|null null = không có câu hỏi này
     */
    public function answer(User $user, Lesson $lesson, string $blockId, array $optionIds, ?CourseEnrollment $enrollment): ?array
    {
        $quiz = LessonContent::quizzes($lesson->content_published)[$blockId] ?? null;
        if (! $quiz) {
            return null;
        }

        $chosen = array_values(array_unique(array_map('strval', $optionIds)));
        sort($chosen);
        $correct = collect($quiz['options'])->where('correct', true)->pluck('id')->sort()->values()->all();
        $isCorrect = $chosen === $correct && ($quiz['type'] === 'multiple' || count($chosen) === 1);

        LessonQuizAnswer::create([
            'user_id' => $user->id,
            'lesson_id' => $lesson->id,
            'block_id' => $blockId,
            'option_ids' => $chosen,
            'is_correct' => $isCorrect,
        ]);

        $progress = $this->viewed($user, $lesson, $enrollment);

        return [
            'correct' => $isCorrect,
            'explanation' => $quiz['explanation'] !== '' ? $quiz['explanation'] : null,
            'progress' => $this->refreshLessonCompletion($user, $lesson, $progress),
        ];
    }

    /** Id các câu hỏi học viên đã trả lời đúng trong bài. @return list<string> */
    public function correctBlockIds(User $user, Lesson $lesson): array
    {
        return LessonQuizAnswer::query()
            ->where('user_id', $user->id)
            ->where('lesson_id', $lesson->id)
            ->where('is_correct', true)
            ->distinct()
            ->pluck('block_id')
            ->all();
    }

    private function refreshLessonCompletion(User $user, Lesson $lesson, LessonProgress $progress): LessonProgress
    {
        if ($progress->completed_at || ! $progress->read_at) {
            return $progress;
        }

        $required = array_keys(LessonContent::quizzes($lesson->content_published));
        if (array_diff($required, $this->correctBlockIds($user, $lesson)) === []) {
            $progress->completed_at = now();
            $progress->save();

            CourseEnrollment::query()
                ->where('user_id', $user->id)
                ->whereNull('completed_at')
                ->whereHas('learningPath.pathLessons', fn ($q) => $q->where('lesson_id', $lesson->id))
                ->with(['learningPath', 'user'])
                ->get()
                ->each(fn (CourseEnrollment $e) => $this->refreshCourseCompletion($e));
        }

        return $progress;
    }

    /** Hoàn thành khoá khi mọi bài (đã xuất bản) của lộ trình đang học đã hoàn thành. */
    public function refreshCourseCompletion(CourseEnrollment $enrollment): void
    {
        $summary = $this->summary($enrollment->loadMissing(['learningPath', 'user']));
        $done = $summary['total'] > 0 && $summary['completed'] === $summary['total'];

        if ($done && ! $enrollment->completed_at) {
            $enrollment->forceFill(['completed_at' => now()])->save();
        } elseif (! $done && $enrollment->completed_at) {
            $enrollment->forceFill(['completed_at' => null])->save(); // lộ trình thêm bài mới / đổi lộ trình
        }
    }
}
