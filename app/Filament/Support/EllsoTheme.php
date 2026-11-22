<?php

namespace App\Filament\Support;

use Filament\Support\Colors\Color;

/**
 * Bảng màu CMS lấy từ design system Ellso (ellso-design-system.md).
 *
 * - primary: emerald của brand. Shade 600 = emerald-700 #047857 (màu hành động: chữ nhấn, liên kết,
 *   mục đang chọn), 500 = emerald-500 #10B981 (màu nhận diện, thanh tiến độ).
 * - gray: slate của brand (ink-900, ink-600, line, surface-alt); 900 / 950 là nền tối #111A2B / #0B1220.
 *
 * Nút chính được tô lại trong resources/views/filament/theme.blade.php (emerald-700, chữ trắng, hover emerald-800).
 */
class EllsoTheme
{
    /** @return array<int, string> */
    public static function primary(): array
    {
        return self::palette([
            50 => '#ecfdf5',
            100 => '#d1fae5',
            200 => '#a7f3d0',
            300 => '#6ee7b7',
            400 => '#34d399',
            500 => '#10b981',
            600 => '#047857',
            700 => '#065f46',
            800 => '#064e3b',
            900 => '#053d2e',
            950 => '#022c22',
        ]);
    }

    /** @return array<int, string> */
    public static function gray(): array
    {
        return self::palette([
            50 => '#f8fafc',
            100 => '#f1f5f9',
            200 => '#e2e8f0',
            300 => '#cbd5e1',
            400 => '#94a3b8',
            500 => '#64748b',
            600 => '#475569',
            700 => '#334155',
            800 => '#1e293b',
            900 => '#111a2b',
            950 => '#0b1220',
        ]);
    }

    /**
     * @param  array<int, string>  $hex
     * @return array<int, string>
     */
    private static function palette(array $hex): array
    {
        return array_map(fn (string $color) => Color::convertToOklch($color), $hex);
    }
}
