<?php

namespace App\Http\Controllers\Api;

use App\Enums\PlacementQuestionType;
use App\Http\Controllers\Controller;
use App\Models\PlacementQuestion;
use App\Models\PlacementTest;
use App\Services\PlacementScoring;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bài kiểm tra đầu vào cho app (học viên đã đăng nhập).
 * - GET  /placement-test           : đề đang dùng (không kèm điểm) + kết quả gần nhất của học viên
 * - POST /placement-test/attempts  : nộp bài → chấm tự động, trả kết quả
 * - GET  /placement-test/result    : kết quả gần nhất (null nếu chưa làm)
 */
class PlacementTestController extends Controller
{
    public function __construct(private readonly PlacementScoring $scoring) {}

    public function show(Request $request): JsonResponse
    {
        $test = PlacementTest::current()?->load('questions.options');

        if (! $test) {
            return response()->json(['data' => null]);
        }

        $latest = $request->user()->placementAttempts()->latest('completed_at')->first();

        return response()->json([
            'data' => [
                'id' => $test->id,
                'title' => $test->title,
                'intro' => $test->intro,
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
                'completed' => (bool) $latest,
                'last_completed_at' => $latest?->completed_at->toIso8601String(),
            ],
        ]);
    }

    public function submit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'test_id' => ['required', 'integer'],
            'answers' => ['required', 'array'],
            'answers.*.question_id' => ['required', 'integer'],
            'answers.*.option_ids' => ['required', 'array', 'min:1'],
            'answers.*.option_ids.*' => ['integer'],
        ], [
            'answers.required' => 'Bạn chưa trả lời câu nào.',
        ]);

        $test = PlacementTest::current();

        if (! $test || $test->id !== (int) $data['test_id']) {
            return response()->json([
                'message' => 'Bài kiểm tra vừa được cập nhật. Vui lòng tải lại và làm lại bài.',
                'code' => 'test_changed',
            ], 409);
        }

        $attempt = $this->scoring->submit($request->user(), $test, $data['answers']);

        return response()->json(['data' => $this->scoring->present($attempt)], 201);
    }

    public function result(Request $request): JsonResponse
    {
        $attempt = $request->user()->placementAttempts()->latest('completed_at')->latest('id')->first();

        return response()->json(['data' => $attempt ? $this->scoring->present($attempt) : null]);
    }
}
