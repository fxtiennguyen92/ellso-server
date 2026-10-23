<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum UserRole: string implements HasLabel, HasColor
{
    case Admin = 'admin';
    case Instructor = 'instructor';
    case Student = 'student';

    public function getLabel(): string
    {
        return match ($this) {
            self::Admin => 'Quản trị viên',
            self::Instructor => 'Giảng viên',
            self::Student => 'Học viên',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Admin => 'danger',
            self::Instructor => 'warning',
            self::Student => 'gray',
        };
    }
}
