<?php

namespace App\Models;

use App\Enums\TrainingCourseStatus;
use App\Models\Concerns\CleansUpFiles;
use App\Support\MediaStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Workshop online: một workshop = một buổi, lặp lại vào nhiều ngày / khung giờ (sessions).
 * Học viên đăng ký từng buổi. capacity = số người tối đa MỖI buổi (null = không giới hạn).
 * price = 0: học viên có gói thành viên không trả thêm; > 0: trả thêm. Đăng ký luôn cần gói thành viên còn hạn.
 * Mỗi buổi có link họp (Zoom / Meet) admin dán tay; app chỉ trả link cho người đã đăng ký, từ 30 phút trước giờ bắt đầu.
 * Trạng thái dùng chung enum với Chương trình huấn luyện (Nháp / Đang mở / Đã ẩn).
 */
class Workshop extends Model
{
    use CleansUpFiles, SoftDeletes;

    protected array $fileAttributes = ['banner_path'];

    protected array $contentFileAttributes = ['content'];

    protected bool $purgeFilesOnSoftDelete = true;

    public const BANNER_WIDTH = MarketingCampaign::BANNER_WIDTH;

    public const BANNER_HEIGHT = MarketingCampaign::BANNER_HEIGHT;

    protected $fillable = ['title', 'summary', 'banner_path', 'price', 'capacity', 'trainer_id', 'content', 'status', 'created_by_id'];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'capacity' => 'integer',
            'content' => 'array',
            'status' => TrainingCourseStatus::class,
        ];
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(WorkshopSession::class)->orderBy('starts_at')->orderBy('id');
    }

    public function upcomingSessions(): HasMany
    {
        return $this->sessions()->upcoming();
    }

    public function registrations(): HasManyThrough
    {
        return $this->hasManyThrough(WorkshopRegistration::class, WorkshopSession::class);
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'trainer_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** Hiện trên app: đang mở và còn ít nhất một buổi sắp diễn ra (chưa huỷ). */
    public function scopeListed(Builder $query): void
    {
        $query->where('status', TrainingCourseStatus::Published)->whereHas('sessions', fn ($q) => $q->upcoming());
    }

    public function hasCapacity(): bool
    {
        return $this->capacity !== null;
    }

    public function bannerUrl(): ?string
    {
        return $this->banner_path ? MediaStorage::disk()->url($this->banner_path) : null;
    }
}
