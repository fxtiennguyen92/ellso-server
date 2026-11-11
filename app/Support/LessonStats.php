<?php

namespace App\Support;

use App\Models\Lesson;
use App\Models\LessonFeedback;
use App\Models\LessonProgress;
use App\Models\LessonQuizAnswer;
use App\Models\LessonSession;
use App\Models\LessonVideo;
use Illuminate\Support\Collection;

/**
 * Số liệu trải nghiệm của một bài học (trang "Thống kê" trong CMS):
 * lượt mở, học viên, thời gian học thực, tỉ lệ đọc hết / hoàn thành, học viên dừng ở đâu (độ sâu cuộn),
 * câu hỏi khó (đúng ngay lần đầu), xem video, đánh giá cuối bài.
 */
class LessonStats
{
    public static function for(Lesson $lesson): array
    {
        $sessions = LessonSession::query()->where('lesson_id', $lesson->id)->get();
        $byUser = $sessions->groupBy('user_id');
        $learners = $byUser->count();

        $progress = LessonProgress::query()->where('lesson_id', $lesson->id)->get();
        $viewed = $progress->whereNotNull('first_viewed_at')->count();
        $read = $progress->whereNotNull('read_at')->count();
        $completed = $progress->whereNotNull('completed_at')->count();

        // Thời gian học thực mỗi học viên (cộng các phiên), lấy trung vị cho đỡ lệch bởi người treo máy
        $timePerUser = $byUser->map(fn (Collection $s) => (int) $s->sum('active_seconds'))->values()->sort()->values();

        // Độ sâu cuộn lớn nhất của mỗi học viên → % học viên đi qua mỗi mốc
        $depth = $byUser->map(fn (Collection $s) => (int) $s->max('max_scroll_percent'));
        $reachedEnd = $byUser->filter(fn (Collection $s) => $s->contains('reached_end', true))->count();
        $dropOff = collect([25, 50, 75, 100])->map(fn ($mark) => [
            'mark' => $mark,
            'percent' => self::pct($depth->filter(fn ($d) => $d >= $mark)->count(), $learners),
        ])->all();

        return [
            'sessions' => $sessions->count(),
            'learners' => $learners,
            'viewed' => $viewed,
            'median_seconds' => self::median($timePerUser),
            'avg_session_seconds' => $sessions->count() ? (int) round($sessions->avg('active_seconds')) : 0,
            'reached_end_percent' => self::pct($reachedEnd, $learners),
            'read_percent' => self::pct($read, $viewed),
            'completed_percent' => self::pct($completed, $viewed),
            'completed' => $completed,
            'drop_off' => $dropOff,
            'platforms' => $sessions->groupBy(fn ($s) => $s->platform ?: 'khác')->map->count()->sortDesc()->all(),
            'quizzes' => self::quizzes($lesson),
            'video' => self::video($lesson, $byUser),
            'feedback' => self::feedback($lesson),
        ];
    }

    /** Mỗi câu hỏi: số học viên trả lời, % đúng ngay lần đầu, số lần trả lời trung bình đến khi đúng. */
    private static function quizzes(Lesson $lesson): array
    {
        $answers = LessonQuizAnswer::query()->where('lesson_id', $lesson->id)->orderBy('created_at')->orderBy('id')->get()->groupBy('block_id');
        $out = [];
        $n = 0;
        foreach (LessonContent::quizzes($lesson->content_published) as $blockId => $quiz) {
            $n++;
            $perUser = ($answers[$blockId] ?? collect())->groupBy('user_id');
            $first = $perUser->filter(fn (Collection $a) => (bool) $a->first()->is_correct)->count();
            $tries = $perUser->map(function (Collection $a) {
                $i = $a->search(fn ($x) => $x->is_correct);

                return $i === false ? null : $i + 1;
            })->filter();
            $out[] = [
                'number' => $n,
                'question' => $quiz['question'],
                'learners' => $perUser->count(),
                'first_try_percent' => self::pct($first, $perUser->count()),
                'avg_tries' => $tries->count() ? round($tries->avg(), 1) : null,
            ];
        }

        return $out;
    }

    /** Video: tổng thời lượng các video trong bài và % thời lượng học viên xem được (trung bình). */
    private static function video(Lesson $lesson, Collection $byUser): ?array
    {
        $ids = LessonContent::videoIds($lesson->content_published);
        if (! $ids) {
            return null;
        }
        $duration = (int) LessonVideo::query()->whereIn('id', $ids)->sum('duration_seconds');
        $watched = $byUser->map(fn (Collection $s) => (int) $s->sum('video_seconds'))->filter(fn ($v) => $v > 0);

        return [
            'count' => count($ids),
            'duration_seconds' => $duration,
            'viewers' => $watched->count(),
            'watch_percent' => $duration > 0 && $watched->count()
                ? min(100, (int) round($watched->avg() / $duration * 100))
                : null,
        ];
    }

    private static function feedback(Lesson $lesson): array
    {
        $all = LessonFeedback::query()->where('lesson_id', $lesson->id)->with('user:id,name')->latest('updated_at')->get();
        $reasons = collect(LessonFeedback::REASONS)->map(fn ($label, $key) => [
            'label' => $label,
            'count' => $all->filter(fn ($f) => in_array($key, $f->reasons ?? [], true))->count(),
        ])->filter(fn ($r) => $r['count'] > 0)->sortByDesc('count')->values()->all();

        return [
            'total' => $all->count(),
            'up' => $all->where('rating', 1)->count(),
            'down' => $all->where('rating', -1)->count(),
            'up_percent' => self::pct($all->where('rating', 1)->count(), $all->count()),
            'reasons' => $reasons,
            'comments' => $all->filter(fn ($f) => filled($f->comment))->take(10)->map(fn ($f) => [
                'name' => $f->user?->name ?? '—',
                'rating' => $f->rating,
                'comment' => $f->comment,
                'at' => $f->updated_at,
            ])->values()->all(),
        ];
    }

    private static function pct(int $part, int $total): ?int
    {
        return $total > 0 ? (int) round($part / $total * 100) : null;
    }

    private static function median(Collection $sorted): int
    {
        $n = $sorted->count();
        if ($n === 0) {
            return 0;
        }

        return $n % 2 ? $sorted[intdiv($n, 2)] : (int) round(($sorted[$n / 2 - 1] + $sorted[$n / 2]) / 2);
    }

    /** 125 → "2 phút 5 giây". */
    public static function duration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds} giây";
        }
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;

        return $s ? "{$m} phút {$s} giây" : "{$m} phút";
    }
}
