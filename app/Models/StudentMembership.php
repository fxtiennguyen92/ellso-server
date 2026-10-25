<?php

namespace App\Models;

use App\Enums\MembershipSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Gói thành viên (gói học viên): luôn 1 năm, mở quyền xem tài liệu và tham gia các khoá huấn luyện.
 * Mỗi lần mua / được tặng = một dòng. Đang có gói còn hạn thì gói mới nối tiếp ngay sau ngày hết hạn
 * (App\Services\MembershipService).
 */
class StudentMembership extends Model
{
    protected $fillable = [
        'user_id',
        'starts_at',
        'ends_at',
        'price',
        'source',
        'membership_campaign_id',
        'source_item_type',
        'source_item_id',
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
            'source' => MembershipSource::class,
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

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MembershipCampaign::class, 'membership_campaign_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** Sản phẩm mua kèm đã tặng gói này (nếu có). */
    public function sourceItem(): ?Model
    {
        $class = MembershipBundleItem::TYPES[$this->source_item_type] ?? null;

        return $class ? $class::withoutGlobalScopes()->find($this->source_item_id) : null;
    }

    public function isActive(): bool
    {
        return $this->starts_at->isPast() && $this->ends_at->isFuture();
    }
}
