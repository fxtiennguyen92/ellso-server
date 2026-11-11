<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Nguồn đăng ký chương trình huấn luyện / workshop. */
enum RegistrationSource: string implements HasColor, HasLabel
{
    case App = 'app';
    case Admin = 'admin';

    public function getLabel(): string
    {
        return match ($this) {
            self::App => 'Tự đăng ký trên app',
            self::Admin => 'Admin thêm',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::App => 'success',
            self::Admin => 'info',
        };
    }
}
