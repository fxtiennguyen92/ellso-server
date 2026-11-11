<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CourseEnrollment;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\PathChapter;
use App\Services\ExamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bài thi trong lộ trình (học viên đã đăng nhập). {exam} = id mục bài thi trong lộ trình (chapters[].id, type = exam).
 * - GET  /my/courses/{course}/exams/{exam}            : thông tin + trạng thái + lần làm đang dở / kết quả gần nhất
 * - POST /my/courses/{course}/exams/{exam}/attempts   : bắt đầu (hoặc tiếp tục lần đang dở) → bộ câu (không có đáp án đúng)
 * - GET  /exam-attempts/{id}                          : lần làm (đang làm / kết quả)
 * - POST /exam-attempts/{id}/submit                   : nộp {answers: [{question_id, option_ids}]} → điểm /100, đậu khi ≥ 70
 */
class ExamController extends Controller
{
    public function __construct(private readonly ExamService $exams) {}

    public function show(Request $request, int $courseId, int $chapterId): JsonResponse
    {
        [$enrollment, $chapter, $state] = $this->resolve($request, $courseId, $chapterId);
        $user = $request->user();
        $open = $this->exams->openAttempt($user, $chapter);
        $submitted = ExamAttempt::query()->where('user_id', $user->id)->where('path_chapter_id', $chapter->id)->whereNotNull('submitted_at');
        $last = (clone $submitted)->latest('submitted_at')->latest('id')->first();
        $state = $this->exams->stateFor($user, $enrollment, $chapter); // có thể vừa tự chấm lần quá giờ

        return response()->json(['data' => [
            ...self::summary($chapter),
            'state' => $state,
            'attempts' => (clone $submitted)->count(),
            'best_score' => (clone $submitted)->max('score') !== null ? (float) (clone $submitted)->max('score') : null,
            'open_attempt' => $open ? $this->exams->presentOpen($open->loadMissing('exam')) : null,
            'last_result' => $last ? $this->exams->presentResult($last) : null,
        ]]);
    }

    public function start(Request $request, int $courseId, int $chapterId): JsonResponse
    {
        [$enrollment, $chapter, $state] = $this->resolve($request, $courseId, $chapterId);

        if ($state === 'locked') {
            return response()->json([
                'message' => 'Hoàn thành các bài học trước để mở bài thi.',
                'code' => 'exam_locked',
            ], 403);
        }
        if ($chapter->exam->questions()->doesntExist()) {
            abort(404, 'Bài thi chưa có câu hỏi.');
        }

        $attempt = $this->exams->start($request->user(), $enrollment, $chapter);

        return response()->json(['data' => $this->exams->presentOpen($attempt->loadMissing('exam'))], $attempt->wasRecentlyCreated ? 201 : 200);
    }

    public function attempt(Request $request, int $id): JsonResponse
    {
        $attempt = $this->ownAttempt($request, $id);
        if ($attempt->isOverdue()) {
            $attempt = $this->exams->submit($attempt, []);
        }

        return response()->json(['data' => $attempt->isOpen()
            ? $this->exams->presentOpen($attempt->loadMissing('exam'))
            : $this->exams->presentResult($attempt)]);
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        $attempt = $this->ownAttempt($request, $id);
        $data = $request->validate([
            'answers' => ['present', 'array'],
            'answers.*.question_id' => ['required', 'integer'],
            'answers.*.option_ids' => ['array'],
            'answers.*.option_ids.*' => ['integer'],
        ]);

        if (! $attempt->isOpen()) {
            return response()->json(['message' => 'Bài này đã nộp.', 'code' => 'already_submitted', 'data' => $this->exams->presentResult($attempt)], 409);
        }

        return response()->json(['data' => $this->exams->presentResult($this->exams->submit($attempt, $data['answers']))]);
    }

    /** Thông tin bài thi trong lộ trình (dùng cả cho bản đồ khoá học). */
    public static function summary(PathChapter $chapter): array
    {
        $exam = $chapter->exam;

        return [
            'id' => $chapter->id,
            'type' => 'exam',
            'title' => $chapter->title,
            'intro' => $exam?->intro,
            'question_count' => $exam ? $exam->questionsPerAttempt() : 0,
            'time_limit_minutes' => $exam?->time_limit_minutes,
            'pass_percent' => Exam::PASS_PERCENT,
        ];
    }

    /** @return array{0: CourseEnrollment, 1: PathChapter, 2: string} */
    private function resolve(Request $request, int $courseId, int $chapterId): array
    {
        $user = $request->user();
        $enrollment = $user->enrollments()->where('course_id', $courseId)->with(['course', 'learningPath', 'user'])->first();
        abort_if(! $enrollment || ! $enrollment->course || $enrollment->course->trashed(), 404, 'Bạn chưa được mở khoá học này.');

        $chapter = PathChapter::query()->with('exam')->find($chapterId);
        $state = $chapter ? $this->exams->stateFor($user, $enrollment, $chapter) : null;
        abort_if($state === null || ! $chapter->exam, 404, 'Bài thi không có trong lộ trình bạn đang học.');

        return [$enrollment, $chapter, $state];
    }

    private function ownAttempt(Request $request, int $id): ExamAttempt
    {
        $attempt = ExamAttempt::query()->whereKey($id)->where('user_id', $request->user()->id)->first();
        abort_if(! $attempt, 404, 'Không tìm thấy bài làm.');

        return $attempt;
    }
}
