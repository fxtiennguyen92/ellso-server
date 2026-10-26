<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserLogEvent;
use App\Enums\UserRole;
use App\Support\ReferralCode;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'avatar',
        'bio',
        'password',
        'role',
        'is_active',
        'password_changed_at',
    ];

    protected $hidden = [
        'password',
        'temp_password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'temp_password' => 'hashed',
            'temp_password_expires_at' => 'datetime',
            'temp_password_sent_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'role' => UserRole::class,
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Học viên mới tạo trong thời gian chiến dịch tặng gói → tặng gói thành viên 1 năm
        static::created(function (User $user) {
            app(\App\Services\MembershipService::class)->grantForSignup($user);
        });

        static::creating(function (User $user) {
            $user->referral_code ??= ReferralCode::generateUnique();
            $user->email = Str::lower(trim((string) $user->email));
        });
    }

    /** User đã tự đặt mật khẩu (đã hoàn tất đăng nhập lần đầu). */
    public function hasActivatedPassword(): bool
    {
        return $this->password_changed_at !== null;
    }

    /**
     * Tài khoản do admin tạo và mời (không tự đăng ký qua app).
     * Bảng người dùng nạp sẵn cờ self_registered để khỏi truy vấn từng dòng.
     */
    public function wasInvited(): bool
    {
        if (array_key_exists('self_registered', $this->attributes)) {
            return ! $this->attributes['self_registered'];
        }

        return $this->logs()->where('event', UserLogEvent::Registered)->doesntExist();
    }

    public function hasValidTempPassword(): bool
    {
        return $this->temp_password !== null
            && ($this->temp_password_expires_at === null || $this->temp_password_expires_at->isFuture());
    }

    public function checkPassword(string $plain): bool
    {
        return $this->hasActivatedPassword() && Hash::check($plain, $this->password);
    }

    public function checkTempPassword(string $plain): bool
    {
        return $this->hasValidTempPassword() && Hash::check($plain, $this->temp_password);
    }

    /** Đặt mật khẩu mới do chính user chọn, hủy mật khẩu tạm. */
    public function setOwnPassword(string $plain): void
    {
        $this->forceFill([
            'password' => $plain,
            'password_changed_at' => now(),
            'temp_password' => null,
            'temp_password_expires_at' => null,
        ])->save();
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by_id');
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(User::class, 'referred_by_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(UserLog::class);
    }

    public function appSessions(): HasMany
    {
        return $this->hasMany(AppSession::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(StudentMembership::class);
    }

    /** Khoá huấn luyện mà giáo viên này hướng dẫn. */
    public function trainingCourses(): HasMany
    {
        return $this->hasMany(TrainingCourse::class, 'trainer_id');
    }

    /** Các lần làm bài kiểm tra đầu vào. */
    public function placementAttempts(): HasMany
    {
        return $this->hasMany(PlacementAttempt::class);
    }

    public function hasActiveMembership(): bool
    {
        return $this->memberships()->active()->exists();
    }

    /** Ngày hết hạn xa nhất của các gói còn hạn (gói nối tiếp tính cả), null nếu không có. */
    public function membershipEndsAt(): ?\Illuminate\Support\Carbon
    {
        $end = $this->memberships()->where('ends_at', '>', now())->max('ends_at');

        return $end ? \Illuminate\Support\Carbon::parse($end) : null;
    }

    /** Chỉ admin và giảng viên (đang hoạt động) được vào CMS. Học viên dùng app. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active
            && in_array($this->role, [UserRole::Admin, UserRole::Instructor], true);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isInstructor(): bool
    {
        return $this->role === UserRole::Instructor;
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class, 'instructor_id');
    }

    /**
     * Câu trả lời phân loại gần nhất của học viên (VD: Nghề nghiệp = Marketing).
     * App luôn hỏi lại khi đăng ký khóa mới, dùng giá trị này làm lựa chọn chọn sẵn.
     */
    public function classificationOptions(): BelongsToMany
    {
        return $this->belongsToMany(ClassificationOption::class, 'user_classification_options')
            ->withPivot('answered_at');
    }

    /**
     * Lưu câu trả lời mới cho các phân loại vừa hỏi: thay câu trả lời cũ của chính phân loại đó,
     * giữ nguyên câu trả lời của phân loại khác.
     *
     * @param  array<int, array<int>>  $answers  [classification_id => [option_id, …]]
     */
    public function rememberClassificationAnswers(array $answers): void
    {
        $classificationIds = array_keys($answers);

        $keep = $this->classificationOptions()
            ->whereNotIn('classification_options.classification_id', $classificationIds)
            ->pluck('classification_options.id')
            ->mapWithKeys(fn ($id) => [$id => []]);

        $new = collect($answers)->flatten()->mapWithKeys(fn ($id) => [(int) $id => ['answered_at' => now()]]);

        $this->classificationOptions()->sync($keep->union($new)->all());
    }
}
