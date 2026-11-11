<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\LessonFeedback;
use App\Models\LessonSession;
use App\Services\LearningProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Theo dõi trải nghiệm học từng bài (để CMS đánh giá bài: thời gian học, độ sâu cuộn, bỏ dở ở đâu, video, đánh giá).
 * - POST  /lessons/{id}/sessions        mở bài → {session_id}
 * - PATCH /lesson-sessions/{id}         app gửi định kỳ (~15 giây) và khi rời bài: active_seconds, max_scroll_percent,
 *                                       reached_end, video_seconds — các số chỉ tăng (lấy max với giá trị đã lưu)
 * - POST  /lessons/{id}/feedback        {rating: 1|-1, reasons?: [hard|long|easy|error|media], comment?} → lưu / sửa
 * Lỗi tracking không ảnh hưởng việc học: app bỏ qua im lặng.
 */
class LessonTrackingController extends Controller
{
    /** Giới hạn hợp lý cho một phiên (tránh số rác): 6 giờ. */
    private const MAX_SECONDS = 6 * 3600;

    public function __construct(private readonly LearningProgress $progress) {}

    public function start(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $lesson = Lesson::query()->find($id);
        abort_if(! $lesson, 404, 'Bài học không tồn tại.');
        $access = $this->progress->access($user, $lesson);
        abort_unless($access['allowed'], 403, 'Bạn chưa mở được bài này.');

        $session = LessonSession::create([
            'user_id' => $user->id,
            'lesson_id' => $lesson->id,
            'course_id' => $access['enrollment']?->course_id,
            'platform' => $request->header('X-Device-Platform'),
            'started_at' => now(),
            'last_seen_at' => now(),
        ]);

        return response()->json(['data' => ['session_id' => $session->id]], 201);
    }

    public function update(Request $request, int $sessionId): JsonResponse
    {
        $session = LessonSession::query()->whereKey($sessionId)->where('user_id', $request->user()->id)->first();
        abort_if(! $session, 404);

        $data = $request->validate([
            'active_seconds' => ['nullable', 'integer', 'min:0'],
            'max_scroll_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'reached_end' => ['nullable', 'boolean'],
            'video_seconds' => ['nullable', 'integer', 'min:0'],
        ]);

        $completed = $session->completed || $request->user()->lessonProgress()
            ->where('lesson_id', $session->lesson_id)->whereNotNull('completed_at')->where('completed_at', '>=', $session->started_at)->exists();

        $session->forceFill([
            'active_seconds' => min(self::MAX_SECONDS, max($session->active_seconds, (int) ($data['active_seconds'] ?? 0))),
            'max_scroll_percent' => max($session->max_scroll_percent, (int) ($data['max_scroll_percent'] ?? 0)),
            'reached_end' => $session->reached_end || (bool) ($data['reached_end'] ?? false),
            'video_seconds' => min(self::MAX_SECONDS, max($session->video_seconds, (int) ($data['video_seconds'] ?? 0))),
            'completed' => $completed,
            'last_seen_at' => now(),
        ])->save();

        return response()->json(['data' => ['ok' => true]]);
    }

    public function feedback(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $lesson = Lesson::query()->find($id);
        abort_if(! $lesson, 404, 'Bài học không tồn tại.');
        abort_unless($this->progress->access($user, $lesson)['allowed'], 403, 'Bạn chưa mở được bài này.');

        $data = $request->validate([
            'rating' => ['required', Rule::in([1, -1])],
            'reasons' => ['nullable', 'array', 'max:5'],
            'reasons.*' => ['string', Rule::in(array_keys(LessonFeedback::REASONS))],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        $feedback = LessonFeedback::updateOrCreate(
            ['user_id' => $user->id, 'lesson_id' => $lesson->id],
            [
                'rating' => (int) $data['rating'],
                'reasons' => (int) $data['rating'] === -1 ? array_values(array_unique($data['reasons'] ?? [])) : null,
                'comment' => $data['comment'] ?? null,
            ],
        );

        return response()->json(['data' => self::presentFeedback($feedback)]);
    }

    public static function presentFeedback(?LessonFeedback $f): ?array
    {
        return $f ? ['rating' => $f->rating, 'reasons' => $f->reasons ?? [], 'comment' => $f->comment] : null;
    }
}
