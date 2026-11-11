<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Lý do cộng / trừ xu. Quy tắc cộng xu cụ thể (bao nhiêu xu cho việc gì) chưa chốt. */
enum CoinSource: string implements HasLabel
{
    case Referral = 'referral'; // giới thiệu bạn bè
    case Learning = 'learning'; // thưởng học tập (hoàn thành bài, khoá…)
    case Spend = 'spend';       // dùng xu
    case Admin = 'admin';       // admin điều chỉnh
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Referral => 'Giới thiệu bạn bè',
            self::Learning => 'Thưởng học tập',
            self::Spend => 'Dùng xu',
            self::Admin => 'Điều chỉnh',
            self::Other => 'Khác',
        };
    }
}
