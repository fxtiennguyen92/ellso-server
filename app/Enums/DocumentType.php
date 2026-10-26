<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum DocumentType: string implements HasColor, HasIcon, HasLabel
{
    case Video = 'video';
    case Pdf = 'pdf';
    case Book = 'book';

    public function getLabel(): string
    {
        return match ($this) {
            self::Video => 'Video',
            self::Pdf => 'PDF',
            self::Book => 'Sách',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Video => 'danger',
            self::Pdf => 'warning',
            self::Book => 'info',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Video => Heroicon::PlayCircle,
            self::Pdf => Heroicon::DocumentText,
            self::Book => Heroicon::BookOpen,
        };
    }
}
