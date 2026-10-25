<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Gói thành viên đến từ đâu. */
enum MembershipSource: string implements HasLabel, HasColor
{
    case Purchase = 'purchase';  // mua gói
    case Campaign = 'campaign';  // tặng khi đăng ký trong chiến dịch
    case Bundle = 'bundle';      // tặng kèm khi mua khoá học / tài liệu được chỉ định
    case Admin = 'admin';        // admin cấp / gia hạn trên CMS

    public function getLabel(): string
    {
        return match ($this) {
            self::Purchase => 'Mua gói',
            self::Campaign => 'Chiến dịch tặng gói',
            self::Bundle => 'Tặng kèm sản phẩm',
            self::Admin => 'Admin cấp',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Purchase => 'success',
            self::Campaign => 'info',
            self::Bundle => 'warning',
            self::Admin => 'gray',
        };
    }
}
