<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Classification;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\PlacementTest;
use App\Models\User;
use App\Services\LearningProgress;
use App\Support\MediaStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Khoá học của tôi (học viên đã đăng nhập).
 * - GET  /my/courses                 : các khoá đã ghi danh + % hoàn thành + bài tiếp theo
 * - GET  /my/courses/{course}        : chưa có lộ trình → câu hỏi phân loại (chọn sẵn câu trả lời cũ);
 *                                      có lộ trình → bản đồ: chương (type chapter, bài lesson | coaching với trạng thái
 *                                      completed | available | locked) và bài thi (type exam: locked | available | passed)
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

        // Chương (kèm bài) và bài thi (ngang cấp chương) theo đúng thứ tự trên bản đồ
        $chapters = [];
        foreach ($this->progress->outline($user, $enrollment->learningPath) as $row) {
            $chapter = $row['chapter'];
            if ($row['type'] === 'exam') {
                $chapters[] = [
                    ...ExamController::summary($chapter),
                    'state' => $row['state'],
                    'passed' => $row['passed'],
                    'best_score' => $row['best']?->score,
                    'attempts' => $row['attempts'],
                ];

                continue;
            }

            $chapters[] = [
                'id' => $chapter->id,
                'type' => 'chapter',
                'title' => $chapter->title,
                'color' => $chapter->color?->value,
                'lessons' => array_map(fn ($r) => [
                    'id' => $r['item']->lesson->id,
                    'type' => $r['item']->lesson->kind, // lesson | coaching
                    'title' => $r['item']->lesson->title,
                    'icon_url' => $r['item']->lesson->icon ? MediaStorage::disk()->url($r['item']->lesson->icon) : null,
                    'state' => $r['state'],
                    'completed_at' => $r['progress']?->completed_at?->toIso8601String(),
                ], $row['items']),
            ];
        }
        $data['chapters'] = $chapters;

        return response()->json(['data' => $data]);
    }

    public function choosePath(Request $request, int $courseId): JsonResponse
    {
        $user = $request->user();
        $enrollment = $this->enrollment($user, $courseId);
        $course = $enrollment->course;

        if ($course->needsPlacementTest($user)) {
            return response()->json(self::placementRequiredBody($course, $user), 409);
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
                throw ValidationException::withMessages(["answers.{$classification->id}" => 'Vui lòng trả lời: '.($classification->question ?: $classification->name)]);
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
            ...self::placementData($course, $user),
            'progress' => ['total' => $s['total'], 'completed' => $s['completed'], 'percent' => $s['percent']],
            'next_lesson' => $s['next'] ? ['id' => $s['next']->lesson->id, 'title' => $s['next']->lesson->title] : null,
            // Nguồn ghi danh (admin | purchase | free | code) + đã học chưa (≥ 1 bài xong hoặc có hoạt động):
            // app đưa lên mục "Tiếp tục khoá học" mọi khoá đã mua / được cấp, và khoá miễn phí đã học ít nhất 1 bài
            'source' => $e->source->value,
            'started' => $s['completed'] > 0 || $e->last_activity_at !== null,
            'enrolled_at' => $e->enrolled_at->toIso8601String(),
            'last_activity_at' => $e->last_activity_at?->toIso8601String(),
            'completed_at' => $e->completed_at?->toIso8601String(),
            // Chứng chỉ: hoàn thành khoá + đậu mọi bài thi của lộ trình
            'certificate' => $this->progress->certificateStatus($e),
        ];
    }

    /**
     * Bài kiểm tra đầu vào khoá yêu cầu: requires_placement_test, needs_placement_test (còn bài chưa làm),
     * placement_tests [{id, title, completed}] (phiên bản đang dùng của từng bài bắt buộc).
     */
    public static function placementData(Course $course, User $user): array
    {
        $required = $course->requiredPlacementTests();
        $pending = $course->pendingPlacementTests($user)->pluck('id')->all();

        return [
            'requires_placement_test' => $required->isNotEmpty(),
            'needs_placement_test' => $pending !== [],
            'placement_tests' => $required->map(fn (PlacementTest $t) => [
                'id' => $t->id,
                'title' => $t->title,
                'completed' => ! in_array($t->id, $pending, true),
            ])->values()->all(),
        ];
    }

    /** Body lỗi 409 placement_test_required: kèm các bài còn phải làm. */
    public static function placementRequiredBody(Course $course, User $user): array
    {
        return [
            'message' => 'Bạn cần làm bài kiểm tra đầu vào trước khi bắt đầu khoá học này.',
            'code' => 'placement_test_required',
            'placement_tests' => $course->pendingPlacementTests($user)
                ->map(fn (PlacementTest $t) => ['id' => $t->id, 'title' => $t->title])->values()->all(),
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
