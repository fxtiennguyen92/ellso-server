<?php

namespace App\Http\Controllers\Api;

use App\Enums\PlacementQuestionType;
use App\Http\Controllers\Controller;
use App\Models\PlacementAttempt;
use App\Models\PlacementQuestion;
use App\Models\PlacementTest;
use App\Models\User;
use App\Services\PlacementScoring;
use App\Support\AbilityClassifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bài kiểm tra đầu vào cho app (học viên đã đăng nhập). Có thể có nhiều bài, mỗi bài một nhóm năng lực.
 * "Đã làm" / kết quả tính theo bài (mọi phiên bản của bài, xem PlacementTest::$key).
 *
 * - GET  /placement-tests                 : các bài đang dùng + trạng thái đã làm của học viên
 * - GET  /placement-tests/{id}            : đề (không kèm điểm)
 * - POST /placement-tests/{id}/attempts   : nộp bài → chấm tự động, trả kết quả
 * - GET  /placement-tests/{id}/result     : kết quả gần nhất của bài này (null nếu chưa làm)
 *
 * Giữ cho bản app cũ (một bài):
 * - GET  /placement-test           : bài đang dùng đầu tiên
 * - POST /placement-test/attempts  : body có test_id
 * - GET  /placement-test/result    : kết quả gần nhất (mọi bài)
 */
class PlacementTestController extends Controller
{
    public function __construct(private readonly PlacementScoring $scoring) {}

    public function index(Request $request): JsonResponse
    {
        $tests = PlacementTest::query()->active()->ordered()->withCount('questions')->get();
        $latest = $this->latestByKey($request->user());

        return response()->json([
            'data' => $tests->map(fn (PlacementTest $test) => [
                ...$this->summary($test, $latest[$test->key] ?? null),
                'question_count' => $test->questions_count,
            ])->values(),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $test = $this->activeTest($id);

        return response()->json(['data' => $this->detail($test, $request->user())]);
    }

    public function submitTo(Request $request, int $id): JsonResponse
    {
        $data = $this->validateAnswers($request, withTestId: false);

        return $this->submitAnswers($request->user(), $id, $data['answers']);
    }

    public function resultOf(Request $request, int $id): JsonResponse
    {
        $test = PlacementTest::query()->findOrFail($id);
        $attempt = $this->latestByKey($request->user())[$test->key] ?? null;

        return response()->json(['data' => $attempt ? $this->scoring->present($attempt) : null]);
    }

    // ---------- Bản app cũ (một bài) ----------

    public function legacyShow(Request $request): JsonResponse
    {
        $test = PlacementTest::query()->active()->ordered()->first();

        return response()->json(['data' => $test ? $this->detail($test, $request->user()) : null]);
    }

    public function submit(Request $request): JsonResponse
    {
        $data = $this->validateAnswers($request, withTestId: true);

        return $this->submitAnswers($request->user(), (int) $data['test_id'], $data['answers']);
    }

    public function result(Request $request): JsonResponse
    {
        $attempt = $request->user()->placementAttempts()->latest('completed_at')->latest('id')->first();

        return response()->json(['data' => $attempt ? $this->scoring->present($attempt) : null]);
    }

    // ---------- Chung ----------

    private function submitAnswers(User $user, int $testId, array $answers): JsonResponse
    {
        $test = PlacementTest::query()->find($testId);

        // Bài đã tắt / được thay bằng phiên bản mới trong lúc học viên làm
        if (! $test || ! $test->is_active) {
            return response()->json([
                'message' => 'Bài kiểm tra vừa được cập nhật. Vui lòng tải lại và làm lại bài.',
                'code' => 'test_changed',
                'replacement_test_id' => $test ? PlacementTest::activeFor($test->key)?->id : null,
            ], 409);
        }

        $attempt = $this->scoring->submit($user, $test, $answers);

        return response()->json(['data' => $this->scoring->present($attempt)], 201);
    }

    private function validateAnswers(Request $request, bool $withTestId): array
    {
        return $request->validate([
            ...($withTestId ? ['test_id' => ['required', 'integer']] : []),
            'answers' => ['required', 'array'],
            'answers.*.question_id' => ['required', 'integer'],
            'answers.*.option_ids' => ['required', 'array', 'min:1'],
            'answers.*.option_ids.*' => ['integer'],
        ], [
            'answers.required' => 'Bạn chưa trả lời câu nào.',
        ]);
    }

    /** Bài đang dùng; id của phiên bản cũ → trả 404 kèm id phiên bản mới để app chuyển sang. */
    private function activeTest(int $id): PlacementTest
    {
        $test = PlacementTest::query()->find($id);

        if (! $test || ! $test->is_active) {
            abort(response()->json([
                'message' => 'Bài kiểm tra không tồn tại hoặc đã được cập nhật.',
                'code' => 'test_not_found',
                'replacement_test_id' => $test ? PlacementTest::activeFor($test->key)?->id : null,
            ], 404));
        }

        return $test;
    }

    /** @return array<string, PlacementAttempt> key bài => bài làm mới nhất */
    private function latestByKey(User $user): array
    {
        return AbilityClassifications::latestAttemptsFor($user)->all();
    }

    private function summary(PlacementTest $test, ?PlacementAttempt $latest): array
    {
        return [
            'id' => $test->id,
            'title' => $test->title,
            'intro' => $test->intro,
            'completed' => (bool) $latest,
            'last_completed_at' => $latest?->completed_at->toIso8601String(),
        ];
    }

    private function detail(PlacementTest $test, User $user): array
    {
        $test->load('questions.options');

        return [
            ...$this->summary($test, $this->latestByKey($user)[$test->key] ?? null),
            'questions' => $test->questions->map(fn (PlacementQuestion $q) => [
                'id' => $q->id,
                'type' => $q->type->value,
                'prompt' => $q->prompt,
                'hint' => $q->hint,
                ...($q->type === PlacementQuestionType::Multiple
                    ? ['min_choices' => $q->choiceBounds()[0], 'max_choices' => $q->choiceBounds()[1]]
                    : []),
                'options' => $q->options->map(fn ($o) => ['id' => $o->id, 'label' => $o->label])->values(),
            ])->values(),
        ];
    }
}
