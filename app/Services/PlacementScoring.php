<?php

namespace App\Services;

use App\Enums\CourseStatus;
use App\Enums\PlacementQuestionType;
use App\Exceptions\PlacementTestChanged;
use App\Models\Course;
use App\Models\PlacementAttempt;
use App\Models\PlacementDimension;
use App\Models\PlacementLevel;
use App\Models\PlacementQuestion;
use App\Models\PlacementTest;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use App\Support\MediaStorage;

/**
 * Chấm điểm tự động bài kiểm tra đầu vào.
 *
 *  - Chọn 1 / chọn nhiều: cộng điểm (scores) của mọi đáp án được chọn vào từng phân loại.
 *  - Sắp xếp: điểm của mục × trọng số của vị trí học viên xếp mục đó (hạng 1, hạng 2, …).
 *  - % điểm = (tổng điểm − điểm thấp nhất có thể) / (cao nhất có thể − thấp nhất có thể) × 100, khoảng điểm tính trên
 *    CHÍNH các câu học viên nhận được (bài rút ngẫu nhiên → mỗi người một bộ câu, % vẫn so sánh được).
 *  - Mức độ của năng lực = mức có min_percent cao nhất mà % điểm ≥ min_percent. Thấp hơn mọi mức → mức thấp nhất.
 *    Bộ câu không có câu nào cộng điểm cho năng lực (khoảng = 0) → chưa xác định được mức (level null).
 *  - Bài rút câu: chấm trên bộ câu của lần làm đó (PlacementTest::draw, seed theo học viên), xem submit().
 */
class PlacementScoring
{
    /**
     * Chuẩn hoá + kiểm tra câu trả lời. Nhận [{question_id, option_ids}] hoặc {question_id: [option_id…]}.
     *
     * @return array<int, list<int>> question_id => option ids (câu sắp xếp: theo thứ tự học viên xếp)
     *
     * @throws ValidationException
     */
    public function normalizeAnswers(PlacementTest $test, array $answers): array
    {
        $byQuestion = [];
        foreach ($answers as $key => $value) {
            if (is_array($value) && array_key_exists('question_id', $value)) {
                $byQuestion[(int) $value['question_id']] = (array) ($value['option_ids'] ?? []);
            } else {
                $byQuestion[(int) $key] = (array) $value;
            }
        }

        $normalized = [];
        $errors = [];

        foreach ($test->questions as $index => $question) {
            $number = $index + 1;
            $chosen = array_map('intval', array_values($byQuestion[$question->id] ?? []));
            $valid = $question->options->pluck('id')->all();

            if ($chosen === []) {
                $errors["answers.{$question->id}"] = "Câu {$number}: chưa trả lời.";

                continue;
            }
            if (array_diff($chosen, $valid) !== [] || count(array_unique($chosen)) !== count($chosen)) {
                $errors["answers.{$question->id}"] = "Câu {$number}: đáp án không hợp lệ.";

                continue;
            }

            $error = match ($question->type) {
                PlacementQuestionType::Single => count($chosen) === 1 ? null : "Câu {$number}: chỉ chọn 1 đáp án.",
                PlacementQuestionType::Multiple => $this->multipleError($question, count($chosen), $number),
                PlacementQuestionType::Ordering => count($chosen) === count($valid) ? null : "Câu {$number}: cần sắp xếp đủ tất cả các mục.",
            };

            if ($error) {
                $errors["answers.{$question->id}"] = $error;

                continue;
            }

            $normalized[$question->id] = $chosen;
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
    }

    private function multipleError(PlacementQuestion $question, int $count, int $number): ?string
    {
        [$min, $max] = $question->choiceBounds();

        if ($count < $min || $count > $max) {
            return $min === $max
                ? "Câu {$number}: chọn đúng {$min} đáp án."
                : "Câu {$number}: chọn từ {$min} đến {$max} đáp án.";
        }

        return null;
    }

    /**
     * Tổng điểm theo phân loại.
     *
     * @param  array<int, list<int>>  $answers  đã chuẩn hoá
     * @return array<int, int> dimension_id => điểm
     */
    public function totals(PlacementTest $test, array $answers): array
    {
        $totals = $test->dimensions->mapWithKeys(fn ($d) => [$d->id => 0])->all();

        foreach ($test->questions as $question) {
            $chosen = $answers[$question->id] ?? [];
            $options = $question->options->keyBy('id');
            $weights = $question->type === PlacementQuestionType::Ordering ? $question->weights() : null;

            foreach ($chosen as $position => $optionId) {
                $option = $options->get($optionId);
                if (! $option) {
                    continue;
                }
                $multiplier = $weights === null ? 1 : ($weights[$position] ?? 0);

                foreach (array_keys($totals) as $dimensionId) {
                    $totals[$dimensionId] += $option->scoreFor($dimensionId) * $multiplier;
                }
            }
        }

        return $totals;
    }

    /** % điểm trong khoảng [min, max] (1 chữ số thập phân); khoảng = 0 → null. */
    public function percent(int $score, int $min, int $max): ?float
    {
        if ($max <= $min) {
            return null;
        }

        return round(max(0, min(100, ($score - $min) / ($max - $min) * 100)), 1);
    }

    /** Mức đạt được với % điểm này. */
    public function levelFor(PlacementDimension $dimension, ?float $percent): ?PlacementLevel
    {
        if ($percent === null) {
            return null;
        }
        $levels = $dimension->levels->sortByDesc('min_percent')->values();

        return $levels->first(fn (PlacementLevel $l) => $percent >= $l->min_percent) ?? $levels->last();
    }

    /**
     * Kết quả theo năng lực (dùng cho cả lưu bài làm, API và "Làm thử" trên CMS).
     * $test->questions phải là bộ câu học viên đã làm (bài rút câu: PlacementTest::withDrawnQuestions).
     *
     * @param  array<int, list<int>>  $answers  đã chuẩn hoá
     */
    public function results(PlacementTest $test, array $answers): array
    {
        $totals = $this->totals($test, $answers);
        $ranges = $this->ranges($test);

        return $test->dimensions->map(function (PlacementDimension $dimension) use ($totals, $ranges) {
            $score = $totals[$dimension->id] ?? 0;
            [$min, $max] = $ranges[$dimension->id];
            $percent = $this->percent($score, $min, $max);
            $level = $this->levelFor($dimension, $percent);
            $levels = $dimension->levels->sortByDesc('min_percent')->values();

            return [
                'dimension_id' => $dimension->id,
                'dimension_key' => $dimension->key,
                'name' => $dimension->name,
                'description' => $dimension->description,
                'score' => $score,
                'min' => $min,
                'max' => $max,
                'percent' => $percent,
                'level_id' => $level?->id,
                'level_key' => $level?->key,
                'level' => $level?->name,
                'level_description' => $level?->description,
                'rank' => $level ? $levels->search(fn ($l) => $l->id === $level->id) + 1 : null, // 1 = mức cao nhất
                'level_count' => $levels->count(),
            ];
        })->values()->all();
    }

    /**
     * Khoảng điểm có thể đạt của từng phân loại (giúp admin đặt ngưỡng mức độ).
     *
     * @return array<int, array{0:int, 1:int}> dimension_id => [thấp nhất, cao nhất]
     */
    public function ranges(PlacementTest $test): array
    {
        $ranges = [];

        foreach ($test->dimensions as $dimension) {
            $min = $max = 0;

            foreach ($test->questions as $question) {
                $points = $question->options->map(fn ($o) => $o->scoreFor($dimension->id))->all();
                if ($points === []) {
                    continue;
                }

                [$qMin, $qMax] = match ($question->type) {
                    PlacementQuestionType::Single => [min($points), max($points)],
                    PlacementQuestionType::Multiple => $this->multipleRange($points, ...$question->choiceBounds()),
                    PlacementQuestionType::Ordering => $this->orderingRange($points, $question->weights(count($points))),
                };
                $min += $qMin;
                $max += $qMax;
            }

            $ranges[$dimension->id] = [$min, $max];
        }

        return $ranges;
    }

    /** Chọn từ $minChoices đến $maxChoices đáp án: lấy tham lam theo điểm. */
    private function multipleRange(array $points, int $minChoices, int $maxChoices): array
    {
        $pick = function (array $sorted, callable $worthAdding) use ($minChoices, $maxChoices) {
            $sum = 0;
            foreach ($sorted as $i => $p) {
                if ($i >= $maxChoices || ($i >= $minChoices && ! $worthAdding($p))) {
                    break;
                }
                $sum += $p;
            }

            return $sum;
        };

        $desc = $points;
        rsort($desc);
        $asc = $points;
        sort($asc);

        return [$pick($asc, fn ($p) => $p < 0), $pick($desc, fn ($p) => $p > 0)];
    }

    /** Sắp xếp: lớn nhất khi mục điểm cao ở vị trí trọng số cao (và ngược lại). */
    private function orderingRange(array $points, array $weights): array
    {
        rsort($points);
        $wDesc = $weights;
        rsort($wDesc);
        $wAsc = $weights;
        sort($wAsc);

        $max = $min = 0;
        foreach ($points as $i => $p) {
            $max += $p * ($wDesc[$i] ?? 0);
            $min += $p * ($wAsc[$i] ?? 0);
        }

        return [$min, $max];
    }

    /**
     * Bộ câu của lần làm tiếp theo của học viên (đã rút + xáo theo cấu hình bài).
     * Cố định cho đến khi học viên nộp bài.
     */
    public function testFor(User $user, PlacementTest $test): PlacementTest
    {
        $test->loadStructure();

        return $test->withDrawnQuestions($test->seedFor($user));
    }

    /**
     * Chấm và lưu bài làm của học viên, trên bộ câu học viên được giao.
     *
     * @throws ValidationException
     * @throws PlacementTestChanged câu trả lời có câu không nằm trong bộ câu (đề / cấu hình vừa đổi)
     */
    public function submit(User $user, PlacementTest $test, array $answers): PlacementAttempt
    {
        $drawn = $this->testFor($user, $test);
        $answeredIds = $this->answeredQuestionIds($answers);
        if (array_diff($answeredIds, $drawn->questions->pluck('id')->all()) !== []) {
            throw new PlacementTestChanged;
        }

        $normalized = $this->normalizeAnswers($drawn, $answers);

        return PlacementAttempt::create([
            'user_id' => $user->id,
            'placement_test_id' => $test->id,
            'answers' => $this->snapshotAnswers($drawn, $normalized),
            'results' => $this->results($drawn, $normalized),
            'completed_at' => now(),
        ]);
    }

    /** @return list<int> */
    private function answeredQuestionIds(array $answers): array
    {
        $ids = [];
        foreach ($answers as $key => $value) {
            $ids[] = is_array($value) && array_key_exists('question_id', $value) ? (int) $value['question_id'] : (int) $key;
        }

        return $ids;
    }

    /** Bản chụp câu trả lời (kèm nội dung câu hỏi/đáp án) để xem lại về sau. */
    public function snapshotAnswers(PlacementTest $test, array $normalized): array
    {
        return $test->questions->map(function (PlacementQuestion $q) use ($normalized) {
            $ids = $normalized[$q->id] ?? [];
            $options = $q->options->keyBy('id');

            return [
                'question_id' => $q->id,
                'prompt' => $q->prompt,
                'type' => $q->type->value,
                'option_ids' => $ids,
                'labels' => array_map(fn ($id) => $options->get($id)?->label, $ids),
            ];
        })->values()->all();
    }

    /** Kết quả trả cho app: phân loại + mức + khoá học gợi ý (theo cấu hình hiện tại của các mức đạt được). */
    public function present(PlacementAttempt $attempt): array
    {
        $levelIds = array_values(array_filter(array_column($attempt->results ?? [], 'level_id')));

        $courses = Course::query()
            ->where('status', CourseStatus::Published)
            ->whereHas('placementLevels', fn ($query) => $query->whereIn('placement_levels.id', $levelIds))
            ->orderBy('title')
            ->get(['id', 'title', 'short_description', 'cover_image']);

        return [
            'id' => $attempt->id,
            'test_id' => $attempt->placement_test_id,
            'test_title' => $attempt->test?->title,
            'completed_at' => $attempt->completed_at->toIso8601String(),
            'dimensions' => array_map(fn (array $r) => [
                'id' => $r['dimension_id'],
                'name' => $r['name'],
                'description' => $r['description'] ?? null,
                'score' => $r['score'],
                'min_score' => $r['min'],
                'max_score' => $r['max'],
                'percent' => $r['percent'] ?? null,
                'level' => $r['level_id'] ? [
                    'id' => $r['level_id'],
                    'name' => $r['level'],
                    'description' => $r['level_description'],
                    'rank' => $r['rank'],
                    'level_count' => $r['level_count'],
                ] : null,
            ], $attempt->results ?? []),
            'recommended_courses' => $courses->map(fn (Course $c) => [
                'id' => $c->id,
                'title' => $c->title,
                'short_description' => $c->short_description,
                'cover_url' => $c->cover_image ? MediaStorage::disk()->url($c->cover_image) : null,
            ])->values()->all(),
        ];
    }
}
