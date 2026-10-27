<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/** Dạng câu hỏi của bài kiểm tra đầu vào. */
enum PlacementQuestionType: string implements HasLabel, HasColor, HasIcon, HasDescription
{
    case Single = 'single';
    case Multiple = 'multiple';
    case Ordering = 'ordering';

    public function getLabel(): string
    {
        return match ($this) {
            self::Single => 'Chọn 1',
            self::Multiple => 'Chọn nhiều',
            self::Ordering => 'Sắp xếp',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Single => 'Cộng điểm của đáp án được chọn.',
            self::Multiple => 'Cộng điểm của mọi đáp án được chọn.',
            self::Ordering => 'Điểm của mục × trọng số vị trí học viên xếp mục đó.',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Single => 'info',
            self::Multiple => 'success',
            self::Ordering => 'warning',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Single => Heroicon::CheckCircle,
            self::Multiple => Heroicon::QueueList,
            self::Ordering => Heroicon::ArrowsUpDown,
        };
    }
}
