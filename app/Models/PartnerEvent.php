<?php

namespace App\Models;

use App\Models\Concerns\CleansUpFiles;
use App\Support\MediaStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sự kiện ngoài lề: điểm tin về sự kiện của đối tác bên ngoài, để học viên biết thêm thông tin.
 * Không đăng ký trong app. Thời gian / địa điểm là chữ tự do; hiển thị trên app trong starts_at – ends_at.
 */
class PartnerEvent extends Model
{
    use CleansUpFiles;

    /** Xoá ảnh khỏi storage khi bỏ / thay ảnh hoặc xoá sự kiện (file còn được bản nhân bản dùng thì giữ). */
    protected array $fileAttributes = ['image_path'];

    /** Kích thước ảnh cố định (px), tỉ lệ 16:9 như banner chiến dịch. */
    public const IMAGE_WIDTH = 1280;

    public const IMAGE_HEIGHT = 720;

    protected $fillable = [
        'title',
        'image_path',
        'time_text',
        'location',
        'description',
        'starts_at',
        'ends_at',
        'is_active',
        'sort_order',
        'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** Đang hiển thị trên app tại thời điểm hiện tại, theo thứ tự trong danh sách CMS. */
    public function scopeVisible(Builder $query): Builder
    {
        $now = now();

        return $query->where('is_active', true)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>', $now)
            ->orderBy('sort_order')
            ->orderByDesc('starts_at');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? MediaStorage::disk()->url($this->image_path) : null;
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
