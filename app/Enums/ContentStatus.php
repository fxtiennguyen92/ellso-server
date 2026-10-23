<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Trạng thái nội dung bài học (tính từ bản nháp / bản đã xuất bản). */
enum ContentStatus: string implements HasLabel, HasColor
{
    case Empty = 'empty';
    case Drafting = 'drafting';
    case Published = 'published';
    case PublishedWithChanges = 'published_with_changes';

    public function getLabel(): string
    {
        return match ($this) {
            self::Empty => 'Chưa có nội dung',
            self::Drafting => 'Đang soạn',
            self::Published => 'Đã xuất bản',
            self::PublishedWithChanges => 'Đã xuất bản · có bản nháp mới',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Empty => 'gray',
            self::Drafting => 'warning',
            self::Published => 'success',
            self::PublishedWithChanges => 'info',
        };
    }
}
