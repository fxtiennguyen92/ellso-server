<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Classification;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\User;
use App\Services\LearningProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use App\Support\MediaStorage;

/**
 * Khoá học của tôi (học viên đã đăng nhập).
 * - GET  /my/courses                 : các khoá đã ghi danh + % hoàn thành + bài tiếp theo
 * - GET  /my/courses/{course}        : chưa có lộ trình → câu hỏi phân loại (chọn sẵn câu trả lời cũ);
 *                                      có lộ trình → bản đồ chương / bài với trạng thái completed | available | locked
 * - POST /my/courses/{course}/path   : trả lời câu hỏi phân loại → gợi ý & gán lộ trình (gồm cả Năng lực từ bài kiểm tra đầu vào)
 */
class MyCourseController extends Controller
{
    public function __construct(private readonly LearningProgress $progress) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $enrollments = $user->enrollments()
            ->with(['course', 'learningPath', 'user'])
            ->orderByDesc('last_activity_at')
            ->orderByDesc('enrolled_at')
            ->get()
            ->filter(fn (CourseEnrollment $e) => $e->course && ! $e->course->trashed());

        return response()->json([
            'data' => $enrollments->map(fn (CourseEnrollment $e) => $this->summary($e, $user))->values(),
        ]);
    }

    public function show(Request $request, int $courseId): JsonResponse
    {
        $user = $request->user();
        $enrollment = $this->enrollment($user, $courseId);

        $data = $this->summary($enrollment, $user);

        if (! $enrollment->learningPath) {
            $data['questions'] = $this->questions($enrollment->course, $user);

            return response()->json(['data' => $data]);
        }

        $chapters = [];
        foreach ($this->progress->itemStates($user, $enrollment->learningPath) as $row) {
            $item = $row['item'];
            $chapterId = $item->path_chapter_id;
            $chapters[$chapterId] ??= [
                'id' => $chapterId,
                'title' => $item->chapter?->title,
                'color' => $item->chapter?->color?->value,
                'lessons' => [],
            ];
            $chapters[$chapterId]['lessons'][] = [
                'id' => $item->lesson->id,
                'title' => $item->lesson->title,
                'icon_url' => $item->lesson->icon ? MediaStorage::disk()->url($item->lesson->icon) : null,
                'state' => $row['state'],
                'completed_at' => $row['progress']?->completed_at?->toIso8601String(),
            ];
        }
        $data['chapters'] = array_values($chapters);

        return response()->json(['data' => $data]);
    }

    public function choosePath(Request $request, int $courseId): JsonResponse
    {
        $user = $request->user();
        $enrollment = $this->enrollment($user, $courseId);
        $course = $enrollment->course;

        if ($course->needsPlacementTest($user)) {
            return response()->json([
                'message' => 'Bạn cần làm bài kiểm tra đầu vào trước khi bắt đầu khoá học này.',
                'code' => 'placement_test_required',
            ], 409);
        }

        $data = $request->validate([
            'answers' => ['nullable', 'array'],
            'answers.*' => ['array'],
            'answers.*.*' => ['integer'],
        ]);

        // Chỉ nhận giá trị của các phân loại "Câu hỏi" mà khoá dùng
        $questions = $course->classifications()->where('type', Classification::TYPE_QUESTION)->with('options:id,classification_id')->get();
        $answers = [];
        foreach ($questions as $classification) {
            $ids = array_values(array_intersect(
                array_map('intval', $data['answers'][$classification->id] ?? []),
                $classification->options->pluck('id')->all(),
            ));
            if ($ids === []) {
                throw ValidationException::withMessages(["answers.{$classification->id}" => 'Vui lòng trả lời: ' . ($classification->question ?: $classification->name)]);
            }
            $answers[$classification->id] = $ids;
        }

        if ($answers) {
            $user->rememberClassificationAnswers($answers);
        }

        $path = $course->suggestPathFor($user, array_merge(...array_values($answers ?: [[]])));
        $enrollment->forceFill(['learning_path_id' => $path?->id])->save();
        $this->progress->refreshCourseCompletion($enrollment->refresh());

        return $this->show($request, $courseId);
    }

    private function enrollment(User $user, int $courseId): CourseEnrollment
    {
        $enrollment = $user->enrollments()->where('course_id', $courseId)->with(['course', 'learningPath', 'user'])->first();

        abort_if(! $enrollment || ! $enrollment->course || $enrollment->course->trashed(), 404, 'Bạn chưa được mở khoá học này.');

        return $enrollment;
    }

    private function summary(CourseEnrollment $e, User $user): array
    {
        $s = $this->progress->summary($e);
        $course = $e->course;

        return [
            'course' => [
                'id' => $course->id,
                'title' => $course->title,
                'short_description' => $course->short_description,
                'cover_url' => $course->cover_image ? MediaStorage::disk()->url($course->cover_image) : null,
                'type' => $course->type->value,
            ],
            'path' => $e->learningPath ? ['id' => $e->learningPath->id, 'name' => $e->learningPath->name] : null,
            // Khoá đa lộ trình chưa có lộ trình → app hỏi câu hỏi phân loại (POST …/path)
            'needs_path' => $e->learningPath === null,
            'needs_placement_test' => $course->needsPlacementTest($user),
            'progress' => ['total' => $s['total'], 'completed' => $s['completed'], 'percent' => $s['percent']],
            'next_lesson' => $s['next'] ? ['id' => $s['next']->lesson->id, 'title' => $s['next']->lesson->title] : null,
            'enrolled_at' => $e->enrolled_at->toIso8601String(),
            'last_activity_at' => $e->last_activity_at?->toIso8601String(),
            'completed_at' => $e->completed_at?->toIso8601String(),
        ];
    }

    /** Câu hỏi phân loại của khoá (loại "Câu hỏi"), chọn sẵn câu trả lời gần nhất của học viên. */
    private function questions(Course $course, User $user): array
    {
        $previous = $user->classificationOptions()->pluck('classification_options.id')->all();

        return $course->classifications()
            ->where('type', Classification::TYPE_QUESTION)
            ->with('options')
            ->get()
            ->map(fn (Classification $c) => [
                'id' => $c->id,
                'question' => $c->question ?: $c->name,
                'options' => $c->options->where('is_active', true)->map(fn ($o) => [
                    'id' => $o->id,
                    'name' => $o->name,
                    'description' => $o->description,
                    'selected' => in_array($o->id, $previous, true),
                ])->values(),
            ])
            ->values()
            ->all();
    }
}
