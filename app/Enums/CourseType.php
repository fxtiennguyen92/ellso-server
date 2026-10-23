<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CourseType: string implements HasLabel
{
    case Single = 'single';
    case MultiPath = 'multi_path';

    public function getLabel(): string
    {
        return match ($this) {
            self::Single => 'Khóa học chung',
            self::MultiPath => 'Đa lộ trình',
        };
    }
}
