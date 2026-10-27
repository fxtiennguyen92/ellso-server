<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Trạng thái tính từ cấu hình + thời điểm hiện tại (không lưu DB). */
enum PromoCodeStatus: string implements HasLabel, HasColor
{
    case Active = 'active';
    case Scheduled = 'scheduled';
    case Paused = 'paused';
    case Expired = 'expired';
    case UsedUp = 'used_up';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'Đang chạy',
            self::Scheduled => 'Sắp diễn ra',
            self::Paused => 'Tạm dừng',
            self::Expired => 'Hết hạn',
            self::UsedUp => 'Hết lượt',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Scheduled => 'info',
            self::Paused => 'warning',
            self::Expired, self::UsedUp => 'gray',
        };
    }
}
