<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\LessonFeedback;
use App\Models\LessonProgress;
use App\Models\User;
use App\Services\LearningProgress;
use App\Support\LessonContent;
use App\Support\MediaStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Học bài (học viên đã đăng nhập).
 * - POST /lessons/{id}/videos/{video}/play : link phát video bài học (HLS có chữ ký, 5 phút)
 * - GET  /lessons/{id}        : nội dung đã xuất bản (câu hỏi không kèm đáp án) + tiến độ; coaching → type coaching, không nội dung
 * - POST /lessons/{id}/read   : đã đọc đến cuối bài
 * - POST /lessons/{id}/quiz   : trả lời một câu hỏi {block_id, option_ids[]} → đúng/sai + giải thích
 * Hoàn thành bài = đọc hết + trả lời đúng mọi câu hỏi (xem App\Services\LearningProgress).
 */
class LessonController extends Controller
{
    public function __construct(private readonly LearningProgress $progress) {}

    public function show(Request $request, int $id): JsonResponse
    {
        [$user, $lesson, $access] = $this->authorizeLesson($request, $id);

        // Coaching: không có nội dung — đặt lịch trao đổi 1-1 với giáo viên (làm sau)
        if ($lesson->isCoaching()) {
            return response()->json(['data' => [
                'id' => $lesson->id,
                'type' => Lesson::KIND_COACHING,
                'title' => $lesson->title,
                'icon_url' => $lesson->icon ? MediaStorage::disk()->url($lesson->icon) : null,
                'course_id' => $access['enrollment']?->course_id,
                'booking' => null,
            ]]);
        }

        $progress = $this->progress->viewed($user, $lesson, $access['enrollment']);
        $answered = $this->progress->correctBlockIds($user, $lesson);

        return response()->json([
            'data' => [
                'id' => $lesson->id,
                'type' => Lesson::KIND_LESSON,
                'title' => $lesson->title,
                'summary' => $lesson->summary,
                'icon_url' => $lesson->icon ? MediaStorage::disk()->url($lesson->icon) : null,
                'course_id' => $access['enrollment']?->course_id,
                'content' => LessonContent::forApp($lesson->content_published, $answered, $lesson->videos()->get()->keyBy('id')->all()),
                'quiz_count' => count(LessonContent::quizzes($lesson->content_published)),
                'progress' => $this->progressData($progress, $lesson, $answered),
                // Đánh giá cuối bài của học viên (null nếu chưa đánh giá) + các lý do có thể chọn
                'my_feedback' => LessonTrackingController::presentFeedback(
                    LessonFeedback::query()->where('user_id', $user->id)->where('lesson_id', $lesson->id)->first(),
                ),
                'feedback_reasons' => collect(LessonFeedback::REASONS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            ],
        ]);
    }

    public function read(Request $request, int $id): JsonResponse
    {
        [$user, $lesson, $access] = $this->authorizeLesson($request, $id);
        abort_if($lesson->isCoaching(), 404, 'Bài coaching không có nội dung.');
        $progress = $this->progress->markRead($user, $lesson, $access['enrollment']);

        return response()->json(['data' => $this->progressData($progress, $lesson, $this->progress->correctBlockIds($user, $lesson))]);
    }

    public function answer(Request $request, int $id): JsonResponse
    {
        [$user, $lesson, $access] = $this->authorizeLesson($request, $id);
        abort_if($lesson->isCoaching(), 404, 'Bài coaching không có câu hỏi.');
        $data = $request->validate([
            'block_id' => ['required', 'string', 'max:32'],
            'option_ids' => ['required', 'array', 'min:1', 'max:'.LessonContent::QUIZ_MAX_OPTIONS],
            'option_ids.*' => ['string', 'max:16'],
        ]);

        $result = $this->progress->answer($user, $lesson, $data['block_id'], $data['option_ids'], $access['enrollment']);
        abort_if($result === null, 404, 'Câu hỏi không tồn tại (bài vừa được cập nhật, hãy tải lại).');

        return response()->json([
            'data' => [
                'correct' => $result['correct'],
                'explanation' => $result['explanation'],
                'progress' => $this->progressData($result['progress'], $lesson, $this->progress->correctBlockIds($user, $lesson)),
            ],
        ]);
    }

    /** @return array{0: User, 1: Lesson, 2: array} */
    /**
     * Link phát video bài học (HLS, hạn 5 phút — hết hạn thì gọi lại). Chỉ video nằm trong nội dung đã xuất bản.
     * Mốc thời gian nằm trong khối video của content (data.chapters).
     */
    public function playVideo(Request $request, int $id, int $videoId): JsonResponse
    {
        [$user, $lesson] = $this->authorizeLesson($request, $id);
        $video = $lesson->videos()->find($videoId);
        abort_unless(
            $video && $video->isReady() && in_array($video->id, LessonContent::videoIds($lesson->content_published), true),
            404,
            'Video không có trong bài học.',
        );

        return response()->json(['data' => [
            'url' => HlsController::lessonMasterUrl($video, $user),
            'expires_at' => now()->addMinutes((int) config('ellso.documents.link_ttl_minutes'))->toIso8601String(),
            'duration_seconds' => $video->duration_seconds,
            'qualities' => collect($video->hls_variants)->pluck('height')->values(),
        ]]);
    }

    private function authorizeLesson(Request $request, int $id): array
    {
        $user = $request->user();
        $lesson = Lesson::query()->find($id);
        abort_if(! $lesson, 404, 'Bài học không tồn tại.');

        $access = $this->progress->access($user, $lesson);
        if (! $access['allowed']) {
            match ($access['reason']) {
                'locked' => abort(response()->json(['message' => 'Hoàn thành bài trước để mở bài này.', 'code' => 'lesson_locked'], 403)),
                'not_enrolled' => abort(response()->json(['message' => 'Bạn chưa được mở khoá học có bài này.', 'code' => 'not_enrolled'], 403)),
                default => abort(404, 'Bài học không tồn tại.'),
            };
        }

        // Khoá bắt buộc làm bài kiểm tra đầu vào trước khi học
        $course = $access['enrollment']?->course;
        if ($course?->needsPlacementTest($user)) {
            abort(response()->json(MyCourseController::placementRequiredBody($course, $user), 409));
        }

        return [$user, $lesson, $access];
    }

    private function progressData(LessonProgress $progress, Lesson $lesson, array $answered): array
    {
        $required = array_keys(LessonContent::quizzes($lesson->content_published));

        return [
            'read' => $progress->read_at !== null,
            'completed' => $progress->completed_at !== null,
            'completed_at' => $progress->completed_at?->toIso8601String(),
            'quizzes_total' => count($required),
            'quizzes_correct' => count(array_intersect($required, $answered)),
        ];
    }
}
