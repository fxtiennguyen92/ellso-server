<?php

use App\Http\Controllers\Api\AppSessionController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CampaignController;
use App\Http\Controllers\Api\PromoCodeController;
use App\Http\Controllers\Api\ReferralController;
use Illuminate\Support\Facades\Route;

// ---- Khách (chưa đăng nhập) ----
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:register');
    Route::post('login', [AuthController::class, 'login']); // tự giới hạn theo email + IP trong controller
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:forgot-password');
    Route::post('resend-temp-password', [AuthController::class, 'resendTempPassword'])->middleware('throttle:forgot-password');

    // Token tạm nhận được khi đăng nhập bằng mật khẩu tạm
    Route::post('set-password', [AuthController::class, 'setPassword'])
        ->middleware(['auth:sanctum', 'abilities:' . AuthController::ABILITY_SET_PASSWORD]);
});

Route::get('referrals/{code}', [ReferralController::class, 'show'])->middleware('throttle:referral-check');

// Chiến dịch marketing: banner trên Home + trang chi tiết
Route::get('campaigns', [CampaignController::class, 'index']);
Route::get('campaigns/{id}', [CampaignController::class, 'show'])->whereNumber('id');

// ---- Đã đăng nhập (token đầy đủ; token đổi mật khẩu không vào được) ----
Route::middleware(['auth:sanctum', 'abilities:app'])->group(function () {
    Route::get('me', [AuthController::class, 'me']);
    Route::get('user', [AuthController::class, 'me']); // giữ tương thích, dùng /me
    Route::post('auth/logout', [AuthController::class, 'logout']);

    // Xem trước mã khuyến mãi khi thanh toán (giới hạn để chống dò mã)
    Route::post('promo-codes/check', [PromoCodeController::class, 'check'])->middleware('throttle:promo-code-check');

    // Lượt mở app (phiên 30 phút): gọi khi mở app, khi quay lại từ nền, và mỗi 60 giây khi đang mở
    Route::post('app-sessions/ping', [AppSessionController::class, 'ping'])->middleware('throttle:app-session-ping');
});
