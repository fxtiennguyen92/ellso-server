<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ReferralCode;
use Illuminate\Http\JsonResponse;

class ReferralController extends Controller
{
    /**
     * GET /referrals/{code} — app kiểm tra mã giới thiệu trong popup ở màn Đăng ký,
     * hợp lệ thì hiển thị tên người giới thiệu.
     */
    public function show(string $code): JsonResponse
    {
        $code = ReferralCode::normalize($code);

        $referrer = $code
            ? User::query()->where('referral_code', $code)->where('is_active', true)->first(['name', 'referral_code'])
            : null;

        if (! $referrer) {
            return response()->json(['message' => 'Mã giới thiệu không hợp lệ.'], 404);
        }

        return response()->json([
            'referral_code' => $referrer->referral_code,
            'name' => $referrer->name,
        ]);
    }
}
