<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\PromoCodeStatus;
use App\Support\ReferralCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Mã khuyến mãi. Điều kiện và cách tính tiền giảm: App\Services\PromoCodeService.
 */
class PromoCode extends Model
{
    use SoftDeletes;

    public const CODE_PATTERN = '/^[A-Z0-9]{8,10}$/';

    public const APPLIES_ALL = 'all';

    public const APPLIES_SELECTED = 'selected';

    protected $fillable = [
        'code',
        'name',
        'description',
        'discount_type',
        'discount_value',
        'max_discount',
        'min_order_amount',
        'starts_at',
        'ends_at',
        'usage_limit',
        'per_user_limit',
        'applies_to',
        'is_active',
        'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'discount_type' => DiscountType::class,
            'discount_value' => 'decimal:2',
            'max_discount' => 'integer',
            'min_order_amount' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'usage_limit' => 'integer',
            'per_user_limit' => 'integer',
            'used_count' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (PromoCode $promo) {
            $promo->code = self::normalize($promo->code);

            // Trần số tiền chỉ có nghĩa với giảm theo %
            if ($promo->discount_type === DiscountType::Fixed) {
                $promo->max_discount = null;
            }
        });
    }

    public static function normalize(?string $code): string
    {
        return strtoupper(preg_replace('/\s+/', '', (string) $code));
    }

    /** Mã ngẫu nhiên chưa dùng (kể cả mã đã xoá), bỏ 0/O/1/I cho dễ đọc. */
    public static function generateUniqueCode(int $length = 8): string
    {
        do {
            $code = '';
            for ($i = 0; $i < $length; $i++) {
                $code .= ReferralCode::ALPHABET[random_int(0, strlen(ReferralCode::ALPHABET) - 1)];
            }
        } while (static::withTrashed()->where('code', $code)->exists());

        return $code;
    }

    public static function findByCode(?string $code): ?self
    {
        $code = self::normalize($code);

        return $code === '' ? null : static::query()->where('code', $code)->first();
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'promo_code_course');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'promo_code_category');
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(PromoCodeRedemption::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function status(): PromoCodeStatus
    {
        return match (true) {
            ! $this->is_active => PromoCodeStatus::Paused,
            $this->ends_at !== null && $this->ends_at->isPast() => PromoCodeStatus::Expired,
            $this->usage_limit !== null && $this->used_count >= $this->usage_limit => PromoCodeStatus::UsedUp,
            $this->starts_at !== null && $this->starts_at->isFuture() => PromoCodeStatus::Scheduled,
            default => PromoCodeStatus::Active,
        };
    }

    public function scopeWithStatus(Builder $query, PromoCodeStatus $status): Builder
    {
        $now = now();
        $notExpired = fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now);
        $notUsedUp = fn (Builder $q) => $q->whereNull('usage_limit')->orWhereColumn('used_count', '<', 'usage_limit');

        return match ($status) {
            PromoCodeStatus::Paused => $query->where('is_active', false),
            PromoCodeStatus::Expired => $query->where('is_active', true)->where('ends_at', '<=', $now),
            PromoCodeStatus::UsedUp => $query->where('is_active', true)->where($notExpired)
                ->whereNotNull('usage_limit')->whereColumn('used_count', '>=', 'usage_limit'),
            PromoCodeStatus::Scheduled => $query->where('is_active', true)->where($notExpired)->where($notUsedUp)
                ->where('starts_at', '>', $now),
            PromoCodeStatus::Active => $query->where('is_active', true)->where($notExpired)->where($notUsedUp)
                ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now)),
        };
    }

    /** Khoá học có nằm trong phạm vi áp dụng (khoá được chọn, hoặc thuộc danh mục / danh mục con được chọn). */
    public function appliesToCourse(Course $course): bool
    {
        if ($this->applies_to !== self::APPLIES_SELECTED) {
            return true;
        }

        if ($this->courses->contains('id', $course->id)) {
            return true;
        }

        if (! $course->category_id) {
            return false;
        }

        $categoryIds = $this->categories->pluck('id');
        $course->loadMissing('category');

        return $categoryIds->contains($course->category_id)
            || ($course->category?->parent_id && $categoryIds->contains($course->category->parent_id));
    }

    /** Tóm tắt mức giảm: "5% · tối đa 500.000 ₫" / "200.000 ₫". */
    public function discountSummary(): string
    {
        if ($this->discount_type === DiscountType::Percent) {
            $pct = rtrim(rtrim(number_format((float) $this->discount_value, 2, ',', '.'), '0'), ',');

            return $pct . '%' . ($this->max_discount ? ' · tối đa ' . self::money($this->max_discount) : '');
        }

        return self::money((int) $this->discount_value);
    }

    public static function money(int $amount): string
    {
        return \App\Support\Vnd::full($amount);
    }
}
