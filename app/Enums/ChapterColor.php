<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Bảng màu chương (bản đồ lộ trình kiểu Duolingo).
 * Mọi màu đạt tương phản ≥ 4.5:1 với icon trắng. Đổi màu thương hiệu → chỉ sửa hex() ở đây.
 */
enum ChapterColor: string implements HasLabel
{
    case Sage = 'sage';
    case Teal = 'teal';
    case Steel = 'steel';
    case Indigo = 'indigo';
    case Plum = 'plum';
    case Rose = 'rose';
    case Terracotta = 'terracotta';
    case Ochre = 'ochre';
    case Graphite = 'graphite';

    public function getLabel(): string
    {
        return match ($this) {
            self::Sage => 'Xanh rêu',
            self::Teal => 'Xanh mòng két',
            self::Steel => 'Xanh thép',
            self::Indigo => 'Chàm',
            self::Plum => 'Tím mận',
            self::Rose => 'Hồng đất',
            self::Terracotta => 'Đất nung',
            self::Ochre => 'Vàng nghệ',
            self::Graphite => 'Than chì',
        };
    }

    public function hex(): string
    {
        return match ($this) {
            self::Sage => '#4E7A5C',
            self::Teal => '#2E6E70',
            self::Steel => '#3D6896',
            self::Indigo => '#4A56A0',
            self::Plum => '#76508A',
            self::Rose => '#A04D66',
            self::Terracotta => '#A9553A',
            self::Ochre => '#94661E',
            self::Graphite => '#3E4651',
        };
    }

    /** Màu kế tiếp cho chương mới, theo số chương đã có. */
    public static function forIndex(int $index): self
    {
        $cases = self::cases();

        return $cases[$index % count($cases)];
    }
}
