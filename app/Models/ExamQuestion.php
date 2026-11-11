<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Câu hỏi trong đề thi: single (chọn 1) | multiple (chọn nhiều). */
class ExamQuestion extends Model
{
    public const TYPES = ['single' => 'Chọn 1 đáp án', 'multiple' => 'Chọn nhiều đáp án'];

    protected $fillable = ['exam_id', 'type', 'prompt', 'explanation', 'sort_order'];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(ExamOption::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return list<int> id các đáp án đúng */
    public function correctIds(): array
    {
        return $this->options->where('is_correct', true)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    /** @return list<string> */
    public function problems(): array
    {
        $options = $this->relationLoaded('options') ? $this->options : $this->options()->get();
        $correct = $options->where('is_correct', true)->count();
        $problems = [];

        if ($options->count() < 2) {
            $problems[] = 'cần ít nhất 2 đáp án.';
        }
        if ($correct === 0) {
            $problems[] = 'chưa đánh dấu đáp án đúng.';
        } elseif ($this->type === 'single' && $correct > 1) {
            $problems[] = '"Chọn 1" chỉ được có 1 đáp án đúng.';
        }

        return $problems;
    }
}
