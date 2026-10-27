<?php

use App\Http\Controllers\Api\AppSessionController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CampaignController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\HlsController;
use App\Http\Controllers\Api\LessonController;
use App\Http\Controllers\Api\MyCourseController;
use App\Http\Controllers\Api\PlacementTestController;
use App\Http\Controllers\Api\PromoCodeController;
use App\Http\Controllers\Api\ReferralController;
use App\Http\Controllers\Api\TrainingController;
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

// Khoá huấn luyện offline: danh sách + chi tiết (lịch, địa điểm)
Route::get('trainings', [TrainingController::class, 'index']);
Route::get('trainings/{id}', [TrainingController::class, 'show'])->whereNumber('id');

// Phát video tài liệu (HLS): link có chữ ký + hạn dùng, không cần header đăng nhập (xem HlsController)
Route::middleware('signed')->prefix('hls/{document}')->whereNumber('document')->group(function () {
    Route::get('master.m3u8', [HlsController::class, 'master'])->name('hls.master');
    Route::get('{height}/index.m3u8', [HlsController::class, 'variant'])->whereNumber('height')->name('hls.variant');
    Route::get('key', [HlsController::class, 'key'])->name('hls.key');
});

// ---- Đã đăng nhập (token đầy đủ; token đổi mật khẩu không vào được) ----
Route::middleware(['auth:sanctum', 'abilities:app'])->group(function () {
    Route::get('me', [AuthController::class, 'me']);
    Route::get('user', [AuthController::class, 'me']); // giữ tương thích, dùng /me
    Route::post('auth/logout', [AuthController::class, 'logout']);

    // Xem trước mã khuyến mãi khi thanh toán (giới hạn để chống dò mã)
    Route::post('promo-codes/check', [PromoCodeController::class, 'check'])->middleware('throttle:promo-code-check');

    // Lượt mở app (phiên 30 phút): gọi khi mở app, khi quay lại từ nền, và mỗi 60 giây khi đang mở
    Route::post('app-sessions/ping', [AppSessionController::class, 'ping'])->middleware('throttle:app-session-ping');

    // Khoá học của tôi + chọn lộ trình
    Route::get('my/courses', [MyCourseController::class, 'index']);
    Route::get('my/courses/{courseId}', [MyCourseController::class, 'show'])->whereNumber('courseId');
    Route::post('my/courses/{courseId}/path', [MyCourseController::class, 'choosePath'])->whereNumber('courseId');

    // Học bài: nội dung, đã đọc hết, trả lời câu hỏi (server chấm)
    Route::get('lessons/{id}', [LessonController::class, 'show'])->whereNumber('id');
    Route::post('lessons/{id}/read', [LessonController::class, 'read'])->whereNumber('id');
    Route::post('lessons/{id}/quiz', [LessonController::class, 'answer'])->whereNumber('id')->middleware('throttle:60,1');

    // Tài liệu: Video (HLS), PDF, Sách
    Route::get('documents', [DocumentController::class, 'index']);
    Route::get('documents/topics', [DocumentController::class, 'topics']);
    Route::get('documents/{id}', [DocumentController::class, 'show'])->whereNumber('id');
    Route::post('documents/{id}/play', [DocumentController::class, 'play'])->whereNumber('id')->middleware('throttle:30,1');
    Route::post('documents/{id}/open', [DocumentController::class, 'open'])->whereNumber('id')->middleware('throttle:30,1');
    Route::post('documents/{id}/save', [DocumentController::class, 'save'])->whereNumber('id');
    Route::delete('documents/{id}/save', [DocumentController::class, 'unsave'])->whereNumber('id');
    Route::get('my/documents', [DocumentController::class, 'mine']);

    // Bài kiểm tra đầu vào: đề đang dùng, nộp bài (chấm tự động), kết quả gần nhất
    Route::get('placement-test', [PlacementTestController::class, 'show']);
    Route::post('placement-test/attempts', [PlacementTestController::class, 'submit'])->middleware('throttle:10,1');
    Route::get('placement-test/result', [PlacementTestController::class, 'result']);
});
