<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Chiến dịch tặng gói thành viên: học viên có tài khoản được tạo trong [starts_at, ends_at]
 * (tự đăng ký hoặc được admin mời) được tặng gói 1 năm miễn phí.
 */
class MembershipCampaign extends Model
{
    protected $fillable = [
        'name',
        'description',
        'starts_at',
        'ends_at',
        'is_active',
        'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /** Chiến dịch đang bật và đang trong thời gian chạy tại thời điểm $at. */
    public function scopeRunningAt(Builder $query, $at = null): Builder
    {
        $at ??= now();

        return $query->where('is_active', true)->where('starts_at', '<=', $at)->where('ends_at', '>=', $at);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(StudentMembership::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** 'scheduled' | 'running' | 'ended' | 'paused' */
    public function state(): string
    {
        return match (true) {
            ! $this->is_active => 'paused',
            $this->starts_at->isFuture() => 'scheduled',
            $this->ends_at->isPast() => 'ended',
            default => 'running',
        };
    }

    public function stateLabel(): string
    {
        return ['paused' => 'Tạm dừng', 'scheduled' => 'Sắp diễn ra', 'ended' => 'Đã kết thúc', 'running' => 'Đang chạy'][$this->state()];
    }

    public function stateColor(): string
    {
        return ['paused' => 'warning', 'scheduled' => 'info', 'ended' => 'gray', 'running' => 'success'][$this->state()];
    }
}
