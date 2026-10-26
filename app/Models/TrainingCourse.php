<?php

namespace App\Models;

use App\Enums\TrainingCourseStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * Khoá huấn luyện offline: 1 khoá = 1 buổi, mở vào nhiều ngày (sessions), giá theo khoá.
 * Nội dung chi tiết dùng chung khối với Chiến dịch (App\Support\CampaignContent).
 */
class TrainingCourse extends Model
{
    use SoftDeletes;

    /** Banner 16:9, cùng kích thước banner chiến dịch. */
    public const BANNER_WIDTH = MarketingCampaign::BANNER_WIDTH;

    public const BANNER_HEIGHT = MarketingCampaign::BANNER_HEIGHT;

    protected $fillable = ['title', 'summary', 'banner_path', 'price', 'trainer_name', 'content', 'status', 'created_by_id'];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'content' => 'array',
            'status' => TrainingCourseStatus::class,
        ];
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(TrainingSession::class)->orderBy('starts_at');
    }

    public function upcomingSessions(): HasMany
    {
        return $this->sessions()->upcoming();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** Hiện trên app: đang mở và còn ít nhất một ngày sắp diễn ra (chưa huỷ). */
    public function scopeListed(Builder $query): void
    {
        $query->where('status', TrainingCourseStatus::Published)->whereHas('sessions', fn ($q) => $q->upcoming());
    }

    public function bannerUrl(): ?string
    {
        return $this->banner_path ? Storage::disk('public')->url($this->banner_path) : null;
    }
}
