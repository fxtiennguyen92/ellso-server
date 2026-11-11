<?php

namespace App\Support;

/** Link YouTube (trailer): kiểm tra + lấy id video. */
class YouTube
{
    private const PATTERN = '~^https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})~';

    public static function isValid(?string $url): bool
    {
        return self::id($url) !== null;
    }

    public static function id(?string $url): ?string
    {
        return $url && preg_match(self::PATTERN, trim($url), $m) ? $m[1] : null;
    }
}
