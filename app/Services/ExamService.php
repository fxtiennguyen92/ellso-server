<?php

namespace App\Services;

use App\Models\CourseEnrollment;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamQuestion;
use App\Models\PathChapter;
use App\Models\User;

/**
 * Làm bài thi trong lộ trình.
 *
 * - start(): mở lần làm mới với bộ câu rút ngẫu nhiên (lưu vào attempt → nộp chấm đúng bộ đó).
 *   Đang có lần làm chưa nộp, chưa hết giờ → trả lại lần đó (tải lại app không đổi đề).
 * - submit(): chấm. Câu đúng khi chọn đúng và đủ các đáp án đúng; câu bỏ trống = sai.
 *   Điểm = câu đúng / tổng × 100 (1 chữ số thập phân), đậu khi ≥ Exam::PASS_PERCENT.
 * - Có giới hạn thời gian: hết giờ + 60 giây ân hạn mà chưa nộp → tự chấm với câu trả lời đã có (= 0 nếu chưa có).
 * - Làm lại không giới hạn, mỗi lần một bộ câu mới.
 */
class ExamService
{
    public function __construct(private readonly LearningProgress $progress) {}

    /**
     * Trạng thái bài thi này trong lộ trình đang học của học viên (locked | available | passed), null = không thuộc lộ trình.
     */
    public function stateFor(User $user, CourseEnrollment $enrollment, PathChapter $chapter): ?string
    {
        if (! $enrollment->learningPath || $chapter->learning_path_id !== $enrollment->learning_path_id || ! $chapter->isExam()) {
            return null;
        }

        $row = collect($this->progress->outline($user, $enrollment->learningPath))
            ->first(fn ($r) => $r['type'] === 'exam' && $r['chapter']->id === $chapter->id);

        return $row['state'] ?? null;
    }

    public function openAttempt(User $user, PathChapter $chapter): ?ExamAttempt
    {
        $attempt = ExamAttempt::query()
            ->where('user_id', $user->id)
            ->where('path_chapter_id', $chapter->id)
            ->whereNull('submitted_at')
            ->latest('id')
            ->first();

        if ($attempt && $attempt->isOverdue()) {
            $this->grade($attempt, $attempt->answers ?? [], timedOut: true);

            return null;
        }

        return $attempt;
    }

    public function start(User $user, CourseEnrollment $enrollment, PathChapter $chapter): ExamAttempt
    {
        if ($open = $this->openAttempt($user, $chapter)) {
            return $open;
        }

        /** @var Exam $exam */
        $exam = $chapter->exam;
        $questions = $exam->draw();

        return ExamAttempt::create([
            'user_id' => $user->id,
            'exam_id' => $exam->id,
            'path_chapter_id' => $chapter->id,
            'course_id' => $enrollment->course_id,
            'questions' => $questions->map(fn (ExamQuestion $q) => [
                'id' => $q->id,
                'type' => $q->type,
                'prompt' => $q->prompt,
                'options' => $q->options->map(fn ($o) => ['id' => $o->id, 'label' => $o->label])->values()->all(),
            ])->values()->all(),
            'started_at' => now(),
            'expires_at' => $exam->time_limit_minutes ? now()->addMinutes($exam->time_limit_minutes) : null,
        ]);
    }

    /**
     * Nộp bài. $answers: [{question_id, option_ids}] hoặc {question_id: [option_ids]}; câu không có trong bộ câu bị bỏ qua.
     */
    public function submit(ExamAttempt $attempt, array $answers): ExamAttempt
    {
        if (! $attempt->isOpen()) {
            return $attempt;
        }

        $normalized = $this->normalize($attempt, $answers);

        // Quá giờ (kể cả ân hạn): không nhận câu trả lời gửi trễ
        if ($attempt->isOverdue()) {
            return $this->grade($attempt, $attempt->answers ?? [], timedOut: true);
        }

        return $this->grade($attempt, $normalized, timedOut: false);
    }

    /** @return array<int, list<int>> question_id => option ids (chỉ câu + đáp án thuộc bộ câu) */
    private function normalize(ExamAttempt $attempt, array $answers): array
    {
        $byQuestion = [];
        foreach ($answers as $key => $value) {
            if (is_array($value) && array_key_exists('question_id', $value)) {
                $byQuestion[(int) $value['question_id']] = (array) ($value['option_ids'] ?? []);
            } else {
                $byQuestion[(int) $key] = (array) $value;
            }
        }

        $out = [];
        foreach ($attempt->questions as $q) {
            $valid = array_column($q['options'], 'id');
            $chosen = array_values(array_unique(array_intersect(array_map('intval', $byQuestion[$q['id']] ?? []), $valid)));
            if ($chosen !== []) {
                $out[$q['id']] = $chosen;
            }
        }

        return $out;
    }

    private function grade(ExamAttempt $attempt, array $answers, bool $timedOut): ExamAttempt
    {
        $questions = ExamQuestion::query()
            ->whereIn('id', array_column($attempt->questions, 'id'))
            ->with('options')
            ->get()
            ->keyBy('id');

        $correctCount = 0;
        $review = [];
        foreach ($attempt->questions as $q) {
            $chosen = array_map('intval', $answers[$q['id']] ?? $answers[(string) $q['id']] ?? []);
            sort($chosen);
            $question = $questions[$q['id']] ?? null;
            $correctIds = $question?->correctIds() ?? [];
            $isCorrect = $question !== null && $chosen !== [] && $chosen === $correctIds
                && ($q['type'] === 'multiple' || count($chosen) === 1);
            $correctCount += $isCorrect ? 1 : 0;

            $labels = collect($q['options'])->pluck('label', 'id');
            $review[] = [
                'question_id' => $q['id'],
                'prompt' => $q['prompt'],
                'correct' => $isCorrect,
                'chosen_labels' => array_values(array_filter(array_map(fn ($id) => $labels[$id] ?? null, $chosen))),
                'correct_labels' => array_values(array_filter(array_map(fn ($id) => $labels[$id] ?? null, $correctIds))),
            ];
        }

        $total = count($attempt->questions);
        $score = $total ? round($correctCount / $total * 100, 1) : 0.0;

        $attempt->forceFill([
            'answers' => $answers,
            'review' => $review,
            'correct_count' => $correctCount,
            'total' => $total,
            'score' => $score,
            'passed' => $total > 0 && $score >= Exam::PASS_PERCENT,
            'submitted_at' => now(),
            'timed_out' => $timedOut,
        ])->save();

        return $attempt;
    }

    /** Dữ liệu trả cho app khi đang làm (không có đáp án đúng). */
    public function presentOpen(ExamAttempt $attempt): array
    {
        return [
            'id' => $attempt->id,
            'status' => 'in_progress',
            'started_at' => $attempt->started_at->toIso8601String(),
            'expires_at' => $attempt->expires_at?->toIso8601String(),
            'time_limit_minutes' => $attempt->exam?->time_limit_minutes,
            'questions' => $attempt->questions,
        ];
    }

    /** Kết quả đã chấm: điểm, đậu, từng câu đúng / sai (không lộ đáp án đúng — học viên làm lại với câu mới). */
    public function presentResult(ExamAttempt $attempt): array
    {
        return [
            'id' => $attempt->id,
            'status' => 'submitted',
            'started_at' => $attempt->started_at->toIso8601String(),
            'submitted_at' => $attempt->submitted_at?->toIso8601String(),
            'timed_out' => $attempt->timed_out,
            'score' => $attempt->score,
            'pass_percent' => Exam::PASS_PERCENT,
            'passed' => $attempt->passed,
            'correct_count' => $attempt->correct_count,
            'total' => $attempt->total,
            'questions' => array_map(fn ($r) => ['question_id' => $r['question_id'], 'correct' => $r['correct']], $attempt->review ?? []),
        ];
    }
}
