<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CourseStatus: string implements HasLabel, HasColor
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Published = 'published';
    case Hidden = 'hidden';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Nháp',
            self::Pending => 'Chờ duyệt',
            self::Published => 'Đã xuất bản',
            self::Hidden => 'Ẩn',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Pending => 'warning',
            self::Published => 'success',
            self::Hidden => 'danger',
        };
    }

    /** Trạng thái giảng viên được tự chọn (xuất bản / ẩn là quyền của admin). */
    public static function instructorOptions(): array
    {
        return [
            self::Draft->value => self::Draft->getLabel(),
            self::Pending->value => self::Pending->getLabel(),
        ];
    }
}
