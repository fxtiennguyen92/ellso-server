<?php

namespace App\Support;

/**
 * Định dạng tiền VND.
 *  full():  9.807.000 ₫
 *  short(): viết tắt kiểu Việt Nam — 500k, 228,1k, 9,81tr, 88,26tr, 1,25 tỷ, 800đ
 *
 * Bản JS cùng quy tắc: Vnd::shortJs() (dùng trong biểu đồ).
 */
class Vnd
{
    public static function full(int $amount): string
    {
        return number_format($amount, 0, ',', '.') . "\u{00A0}₫";
    }

    public static function short(int $amount): string
    {
        $sign = $amount < 0 ? '-' : '';
        $v = abs($amount);

        return $sign . match (true) {
            $v >= 1_000_000_000 => self::trim($v / 1_000_000_000, 2) . "\u{00A0}tỷ",
            $v >= 1_000_000 => self::trim($v / 1_000_000, 2) . 'tr',
            $v >= 1_000 => self::trim($v / 1_000, 1) . 'k',
            default => $v . 'đ',
        };
    }

    /** Hàm JS (v) => chuỗi viết tắt, cùng quy tắc với short(). */
    public static function shortJs(): string
    {
        return <<<'JS'
            (v) => {
                const f = (n, d) => (Math.round(n * 10 ** d) / 10 ** d).toLocaleString('vi-VN', { maximumFractionDigits: d });
                const a = Math.abs(v), s = v < 0 ? '-' : '';
                if (a >= 1e9) return s + f(a / 1e9, 2) + ' tỷ';
                if (a >= 1e6) return s + f(a / 1e6, 2) + 'tr';
                if (a >= 1e3) return s + f(a / 1e3, 1) + 'k';
                return s + a + 'đ';
            }
        JS;
    }

    private static function trim(float $value, int $decimals): string
    {
        $text = number_format(round($value, $decimals), $decimals, ',', '.');

        return str_contains($text, ',') ? rtrim(rtrim($text, '0'), ',') : $text;
    }
}
