<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Gói học viên: một giá, thời hạn 1 năm (cấu hình student_membership_months),
 * mở quyền xem tài liệu và tham gia các khoá huấn luyện trong thời hạn gói.
 * Gia hạn = thêm một dòng mới bắt đầu khi gói hiện tại hết hạn.
 */
class StudentMembership extends Model
{
    protected $fillable = [
        'user_id',
        'starts_at',
        'ends_at',
        'price',
        'source',
        'order_id',
        'created_by_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'price' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        $now = now();

        return $query->where('starts_at', '<=', $now)->where('ends_at', '>', $now);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->starts_at->isPast() && $this->ends_at->isFuture();
    }
}
