<?php

namespace App\Models;

use App\Models\Concerns\CleansUpFiles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * Chiến dịch marketing hiển thị trên Home của app (banner) + trang chi tiết.
 * Nội dung chi tiết là danh sách khối (content), app vẽ từng khối bằng giao diện native.
 * Xem App\Support\CampaignContent cho các loại khối.
 */
class MarketingCampaign extends Model
{
    use CleansUpFiles, SoftDeletes;

    /** Ảnh banner / ảnh trong nội dung: xoá file khi bỏ ảnh hoặc xoá chiến dịch (không có thùng rác). */
    protected array $fileAttributes = ['banner_path'];

    protected array $contentFileAttributes = ['content'];

    protected bool $purgeFilesOnSoftDelete = true;

    /** Kích thước banner cố định (px). Tỉ lệ 16:9, thẻ chiến dịch trên Home của app theo đúng tỉ lệ này. */
    public const BANNER_WIDTH = 1280;

    public const BANNER_HEIGHT = 720;

    public const CTA_NONE = 'none';

    public const CTA_URL = 'url';

    public const CTA_COURSE = 'course';

    protected $fillable = [
        'title',
        'summary',
        'banner_path',
        'starts_at',
        'ends_at',
        'is_active',
        'sort_order',
        'content',
        'cta_type',
        'cta_label',
        'cta_url',
        'cta_course_id',
        'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'content' => 'array',
        ];
    }

    /** Đang hiển thị trên app tại thời điểm hiện tại, theo thứ tự banner. */
    public function scopeVisible(Builder $query): Builder
    {
        $now = now();

        return $query->where('is_active', true)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>', $now)
            ->orderBy('sort_order')
            ->orderByDesc('starts_at');
    }

    public function ctaCourse(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'cta_course_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function bannerUrl(): ?string
    {
        return $this->banner_path ? Storage::disk('public')->url($this->banner_path) : null;
    }

    /** 'hidden' | 'scheduled' | 'live' | 'ended' */
    public function state(): string
    {
        return match (true) {
            ! $this->is_active => 'hidden',
            $this->starts_at->isFuture() => 'scheduled',
            $this->ends_at->isPast() => 'ended',
            default => 'live',
        };
    }

    public function stateLabel(): string
    {
        return ['hidden' => 'Đang ẩn', 'scheduled' => 'Sắp hiển thị', 'ended' => 'Đã kết thúc', 'live' => 'Đang hiển thị'][$this->state()];
    }

    public function stateColor(): string
    {
        return ['hidden' => 'warning', 'scheduled' => 'info', 'ended' => 'gray', 'live' => 'success'][$this->state()];
    }
}
