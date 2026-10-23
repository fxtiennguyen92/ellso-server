<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
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
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
        ];
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
