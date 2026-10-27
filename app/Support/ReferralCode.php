<?php

namespace App\Support;

use App\Models\User;

/** Mã giới thiệu: 8 ký tự in hoa A-Z và 0-9, bỏ 0/O/1/I để dễ đọc, dễ gõ. */
class ReferralCode
{
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const LENGTH = 8;

    public static function generate(): string
    {
        $code = '';
        $max = strlen(self::ALPHABET) - 1;

        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }

    public static function generateUnique(): string
    {
        do {
            $code = self::generate();
        } while (User::query()->where('referral_code', $code)->exists());

        return $code;
    }

    /** Chuẩn hoá mã người dùng nhập: bỏ khoảng trắng, viết hoa. */
    public static function normalize(?string $code): ?string
    {
        $code = strtoupper(preg_replace('/\s+/', '', (string) $code));

        return $code === '' ? null : $code;
    }
}
