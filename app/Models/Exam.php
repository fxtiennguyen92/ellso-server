<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Đề thi trong ngân hàng đề (dùng chung cho nhiều lộ trình / khoá).
 * Đặt vào lộ trình như một mục ngang cấp chương (PathChapter kind = exam).
 *
 * - Câu chọn 1 / chọn nhiều, có đáp án đúng. Câu đúng khi chọn đúng và đủ các đáp án đúng.
 * - Điểm = số câu đúng / số câu × 100. Đậu khi ≥ PASS_PERCENT.
 * - question_count: số câu mỗi lần làm (null = tất cả), rút ngẫu nhiên; shuffle_options: đảo đáp án.
 * - time_limit_minutes: null = không giới hạn.
 */
class Exam extends Model
{
    public const PASS_PERCENT = 70;

    protected $fillable = ['title', 'intro', 'question_count', 'shuffle_options', 'time_limit_minutes', 'created_by_id'];

    protected $attributes = ['shuffle_options' => true];

    protected function casts(): array
    {
        return [
            'question_count' => 'integer',
            'shuffle_options' => 'boolean',
            'time_limit_minutes' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Exam $exam) {
            $exam->created_by_id ??= auth()->id();
        });
    }

    public function questions(): HasMany
    {
        return $this->hasMany(ExamQuestion::class)->orderBy('sort_order')->orderBy('id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(ExamAttempt::class);
    }

    /** Các vị trí trong lộ trình đang dùng đề này. */
    public function pathChapters(): HasMany
    {
        return $this->hasMany(PathChapter::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** Số câu học viên nhận mỗi lần làm. */
    public function questionsPerAttempt(?int $total = null): int
    {
        $total ??= $this->relationLoaded('questions') ? $this->questions->count() : $this->questions()->count();

        return $this->question_count ? min($this->question_count, $total) : $total;
    }

    /**
     * Rút bộ câu cho một lần làm: N câu ngẫu nhiên (hoặc tất cả, theo thứ tự soạn), đáp án đảo nếu bật.
     *
     * @return Collection<int, ExamQuestion> đã nạp options theo thứ tự hiển thị
     */
    public function draw(): Collection
    {
        $questions = $this->questions()->with('options')->get();

        if ($this->question_count) {
            $questions = $questions->shuffle()->take($this->question_count)->values();
        }

        return $questions->map(function (ExamQuestion $question) {
            if ($this->shuffle_options) {
                $question->setRelation('options', $question->options->shuffle()->values());
            }

            return $question;
        });
    }

    /** Vấn đề làm đề chưa dùng được (hiện trên CMS). @return list<string> */
    public function problems(): array
    {
        $questions = $this->questions()->with('options')->get();
        $problems = [];

        if ($questions->isEmpty()) {
            $problems[] = 'Chưa có câu hỏi nào.';
        }
        foreach ($questions as $i => $question) {
            $problems = [...$problems, ...array_map(fn ($p) => 'Câu ' . ($i + 1) . ': ' . $p, $question->problems())];
        }

        return $problems;
    }
}
