<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Người dùng app trên route public (token không bắt buộc): có Bearer token app hợp lệ → User, không thì null.
 * Không nhận token chỉ dùng để đổi mật khẩu, tài khoản bị khoá.
 */
class OptionalAppUser
{
    public static function from(Request $request): ?User
    {
        if (! $request->bearerToken()) {
            return null;
        }

        $user = auth('sanctum')->user();

        return $user instanceof User && $user->is_active && $user->tokenCan('app') ? $user : null;
    }
}
