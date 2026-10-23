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

        return trim($text . ' ' . ($this->unit ?? ''));
    }
}
