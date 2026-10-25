<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Cấu hình hệ thống dạng key/value. Thêm thông số mới bằng migration (insert 1 dòng),
 * admin chỉ sửa giá trị trên CMS.
 *
 * Dùng: SystemSetting::get('referral_commission_percent'), hoặc các helper bên dưới.
 */
class SystemSetting extends Model
{
    public const REFERRAL_COMMISSION_PERCENT = 'referral_commission_percent';

    public const CREDIT_TO_VND_RATE = 'credit_to_vnd_rate';

    public const SUPPORT_EMAIL = 'support_email';

    public const COMPANY_NAME = 'company_name';

    public const COMPANY_ADDRESS = 'company_address';

    public const STUDENT_MEMBERSHIP_PRICE = 'student_membership_price';

    public const STUDENT_MEMBERSHIP_MONTHS = 'student_membership_months';

    private const CACHE_KEY = 'system_settings';

    protected $fillable = [
        'key',
        'value',
        'type',
        'group',
        'label',
        'unit',
        'description',
        'sort_order',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = Cache::rememberForever(self::CACHE_KEY, fn () => static::query()
            ->get(['key', 'value', 'type'])
            ->mapWithKeys(fn (self $s) => [$s->key => $s->typedValue()])
            ->all());

        return $all[$key] ?? $default;
    }

    public static function referralCommissionPercent(): float
    {
        return (float) static::get(self::REFERRAL_COMMISSION_PERCENT, 0);
    }

    public static function creditToVndRate(): int
    {
        return (int) static::get(self::CREDIT_TO_VND_RATE, 0);
    }

    public static function supportEmail(): ?string
    {
        return static::get(self::SUPPORT_EMAIL) ?: null;
    }

    public static function companyName(): ?string
    {
        return static::get(self::COMPANY_NAME) ?: null;
    }

    public static function companyAddress(): ?string
    {
        return static::get(self::COMPANY_ADDRESS) ?: null;
    }

    public static function studentMembershipPrice(): ?int
    {
        $price = static::get(self::STUDENT_MEMBERSHIP_PRICE);

        return $price === null ? null : (int) $price;
    }

    public static function studentMembershipMonths(): int
    {
        return (int) static::get(self::STUDENT_MEMBERSHIP_MONTHS, 12) ?: 12;
    }

    public function typedValue(): mixed
    {
        if ($this->value === null) {
            return null;
        }

        return match ($this->type) {
            'integer' => (int) $this->value,
            'decimal' => (float) $this->value,
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($this->value, true),
            default => $this->value,
        };
    }

    public function displayValue(): string
    {
        $value = $this->typedValue();

        $text = match ($this->type) {
            'integer' => number_format((int) $value, 0, ',', '.'),
            'decimal' => rtrim(rtrim(number_format((float) $value, 4, ',', '.'), '0'), ','),
            'boolean' => $value ? 'Bật' : 'Tắt',
            'json' => json_encode($value, JSON_UNESCAPED_UNICODE),
            default => (string) $value,
        };

        if ($text === '') {
            return '—';
        }

        return trim($text . ' ' . ($this->unit ?? ''));
    }
}
