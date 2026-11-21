<?php

namespace App\Models;

use App\Enums\TrainingCourseStatus;
use App\Models\Concerns\CleansUpFiles;
use App\Support\MediaStorage;
use App\Support\YouTube;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Chương trình huấn luyện offline: một chương trình diễn ra qua nhiều buổi (sessions: buổi 1 … buổi n, theo ngày).
 * Học viên đăng ký CẢ chương trình (registrations); capacity = số học viên tối đa của chương trình.
 * price = 0: học viên có gói thành viên không trả thêm; > 0: trả thêm. Đăng ký luôn cần gói thành viên còn hạn.
 * Trailer: file video (trailer_video_path, disk media, không chuyển HLS) HOẶC link YouTube (trailer_youtube_url).
 * Nội dung chi tiết dùng chung khối với Chiến dịch (App\Support\CampaignContent).
 */
class TrainingCourse extends Model
{
    use CleansUpFiles, SoftDeletes;

    /** Banner / trailer / ảnh trong nội dung: xoá file khi bỏ hoặc thay, hoặc xoá chương trình (không có thùng rác). */
    protected array $fileAttributes = ['banner_path', 'trailer_video_path'];

    protected array $contentFileAttributes = ['content'];

    protected bool $purgeFilesOnSoftDelete = true;

    /** Banner 16:9, cùng kích thước banner chiến dịch. */
    public const BANNER_WIDTH = MarketingCampaign::BANNER_WIDTH;

    public const BANNER_HEIGHT = MarketingCampaign::BANNER_HEIGHT;

    protected $fillable = [
        'title', 'summary', 'banner_path', 'trailer_video_path', 'trailer_youtube_url',
        'price', 'capacity', 'trainer_id', 'content', 'status', 'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'capacity' => 'integer',
            'content' => 'array',
            'status' => TrainingCourseStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::replicating(fn (self $copy) => $copy->checkin_token = null); // bản sao có mã QR riêng
        static::creating(function (TrainingCourse $c) {
            $c->checkin_token ??= Str::random(32);
        });
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(TrainingSession::class)->orderBy('starts_at')->orderBy('id');
    }

    public function upcomingSessions(): HasMany
    {
        return $this->sessions()->upcoming();
    }

    /** Các buổi chưa huỷ, theo ngày (buổi 1 … buổi n). */
    public function activeSessions(): HasMany
    {
        return $this->sessions()->whereNull('cancelled_at');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(TrainingRegistration::class);
    }

    /** Người hướng dẫn: tài khoản Giảng viên phụ trách chương trình. */
    public function trainer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'trainer_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * Hiện trên app: đang mở và còn ít nhất một buổi CHƯA KẾT THÚC (chưa huỷ) — tức là hiện cho đến khi qua hết buổi cuối,
     * kể cả lúc chương trình đang diễn ra.
     */
    public function scopeListed(Builder $query): void
    {
        $query->where('status', TrainingCourseStatus::Published)->whereHas('sessions', fn ($q) => $q->notEnded());
    }

    /** Đang diễn ra: buổi 1 đã bắt đầu và buổi cuối chưa kết thúc. */
    public function isOngoing(): bool
    {
        $sessions = $this->relationLoaded('activeSessions') ? $this->activeSessions : $this->activeSessions()->get();
        $first = $sessions->first();
        $last = $sessions->last();

        return $first !== null && $first->starts_at->isPast() && $last->ends_at->isFuture();
    }

    /** Nội dung QR điểm danh của chương trình (học viên tự quét bằng app; chỉ dùng được trong ngày có buổi học). */
    public const CHECKIN_PREFIX = 'ELLSO-CHECKIN:';

    public function checkinQrValue(): string
    {
        if (! $this->checkin_token) {
            $this->forceFill(['checkin_token' => Str::random(32)])->save();
        }

        return self::CHECKIN_PREFIX.$this->checkin_token;
    }

    /** Buổi (chưa huỷ) diễn ra trong ngày $day theo giờ Việt Nam — buổi mà QR điểm danh đang có hiệu lực. */
    public function sessionOn(?CarbonInterface $day = null): ?TrainingSession
    {
        $date = ($day ?? now())->copy()->timezone(TrainingSession::TZ)->toDateString();

        return $this->activeSessions()->get()
            ->first(fn (TrainingSession $s) => $s->starts_at->copy()->timezone(TrainingSession::TZ)->toDateString() === $date);
    }

    /** Số thứ tự buổi (chỉ tính buổi chưa huỷ): [session_id => n]. */
    public function sessionNumbers(): array
    {
        $sessions = $this->relationLoaded('activeSessions') ? $this->activeSessions : $this->activeSessions()->get(['id']);

        return $sessions->pluck('id')->values()->mapWithKeys(fn ($id, $i) => [$id => $i + 1])->all();
    }

    /** Buổi 1 (buổi chưa huỷ sớm nhất). */
    public function firstSession(): ?TrainingSession
    {
        return $this->relationLoaded('activeSessions') ? $this->activeSessions->first() : $this->activeSessions()->first();
    }

    /** Còn nhận đăng ký trên app: đang mở và buổi 1 chưa bắt đầu. */
    public function isRegistrationOpen(): bool
    {
        $first = $this->firstSession();

        return $this->status === TrainingCourseStatus::Published && $first !== null && $first->starts_at->isFuture();
    }

    public function seatsLeft(?int $registered = null): int
    {
        return max(0, $this->capacity - ($registered ?? $this->registrations()->count()));
    }

    public function bannerUrl(): ?string
    {
        return $this->banner_path ? MediaStorage::disk()->url($this->banner_path) : null;
    }

    /** {type: file, url} | {type: youtube, url, youtube_id} | null */
    public function trailer(): ?array
    {
        if ($this->trailer_video_path) {
            return ['type' => 'file', 'url' => MediaStorage::disk()->url($this->trailer_video_path)];
        }
        if ($this->trailer_youtube_url) {
            return ['type' => 'youtube', 'url' => $this->trailer_youtube_url, 'youtube_id' => YouTube::id($this->trailer_youtube_url)];
        }

        return null;
    }
}
