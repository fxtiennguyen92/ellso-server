<?php

namespace App\Support;

/** Mật khẩu tạm 12 ký tự: có chữ hoa, chữ thường và số; bỏ các ký tự dễ nhầm (0/O, 1/l/I). */
class TempPassword
{
    private const UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const LOWER = 'abcdefghijkmnopqrstuvwxyz';

    private const DIGITS = '23456789';

    public const LENGTH = 12;

    public static function generate(): string
    {
        $all = self::UPPER . self::LOWER . self::DIGITS;

        // Đảm bảo mỗi nhóm có ít nhất 1 ký tự
        $chars = [self::pick(self::UPPER), self::pick(self::LOWER), self::pick(self::DIGITS)];

        while (count($chars) < self::LENGTH) {
            $chars[] = self::pick($all);
        }

        // Xáo trộn bằng nguồn ngẫu nhiên an toàn (Fisher–Yates)
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    private static function pick(string $pool): string
    {
        return $pool[random_int(0, strlen($pool) - 1)];
    }
}
