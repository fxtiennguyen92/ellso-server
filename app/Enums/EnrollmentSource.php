<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Học viên được vào khoá học từ đâu. */
enum EnrollmentSource: string implements HasColor, HasLabel
{
    case Admin = 'admin';        // admin mở trên CMS (Học bạ)
    case Purchase = 'purchase';  // mua khoá (làm cùng đợt thanh toán)
    case Free = 'free';          // khoá miễn phí, học viên tự vào
    case Code = 'code';          // mã kích hoạt (làm sau)

    public function getLabel(): string
    {
        return match ($this) {
            self::Admin => 'Admin mở',
            self::Purchase => 'Mua khoá',
            self::Free => 'Miễn phí',
            self::Code => 'Mã kích hoạt',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Admin => 'gray',
            self::Purchase => 'success',
            self::Free => 'info',
            self::Code => 'warning',
        };
    }
}
