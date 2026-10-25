<?php

namespace App\Filament\AvatarProviders;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Avatar chữ cái đầu, tạo ngay trên server (SVG data URI).
 * Thay cho ui-avatars.com mặc định của Filament: không chờ dịch vụ ngoài khi mở trang,
 * và không gửi tên nhân viên ra bên thứ ba.
 */
class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model $record): string
    {
        $name = trim((string) Filament::getNameForDefaultAvatar($record));

        $initials = collect(preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY))
            ->pipe(fn ($words) => $words->count() > 1 ? collect([$words->first(), $words->last()]) : $words)
            ->map(fn (string $word) => Str::upper(mb_substr($word, 0, 1)))
            ->join('') ?: '?';

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
            . '<rect width="64" height="64" fill="#047857"/>'
            . '<text x="50%" y="50%" dy=".35em" text-anchor="middle" fill="#ffffff" '
            . 'font-family="Be Vietnam Pro, Arial, Helvetica, sans-serif" font-size="26" font-weight="600">'
            . e($initials)
            . '</text></svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
