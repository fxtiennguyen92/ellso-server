<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Quy tắc mở khóa — đặt theo vị trí bài trong lộ trình. */
enum UnlockMode: string implements HasLabel
{
    case AfterPrevious = 'after_previous';
    case Always = 'always';

    public function getLabel(): string
    {
        return match ($this) {
            self::AfterPrevious => 'Mở khi xong bài trước',
            self::Always => 'Luôn mở',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::AfterPrevious => 'Sau bài trước',
            self::Always => 'Luôn mở',
        };
    }
}
