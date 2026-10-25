<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một lần làm bài kiểm tra đầu vào. answers/results là bản chụp lúc nộp
 * (giữ nguyên dù sau này đề hay cách chấm thay đổi).
 */
class PlacementAttempt extends Model
{
    protected $fillable = ['user_id', 'placement_test_id', 'answers', 'results', 'completed_at'];

    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'results' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function test(): BelongsTo
    {
        return $this->belongsTo(PlacementTest::class, 'placement_test_id');
    }

    /** "Khả năng tiếp thu: Xuất sắc", … */
    public function resultLabels(): array
    {
        return array_map(fn (array $r) => $r['name'] . ': ' . ($r['level'] ?? '—'), $this->results ?? []);
    }
}
