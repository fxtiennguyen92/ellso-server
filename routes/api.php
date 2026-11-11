<?php

use App\Http\Controllers\Api\AppConfigController;
use App\Http\Controllers\Api\AppSessionController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CampaignController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CourseCatalogController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\ExamController;
use App\Http\Controllers\Api\HlsController;
use App\Http\Controllers\Api\LessonController;
use App\Http\Controllers\Api\LessonTrackingController;
use App\Http\Controllers\Api\MyCourseController;
use App\Http\Controllers\Api\PartnerEventController;
use App\Http\Controllers\Api\PlacementTestController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PromoCodeController;
use App\Http\Controllers\Api\ReaderController;
use App\Http\Controllers\Api\ReferralController;
use App\Http\Controllers\Api\TrainingController;
use App\Http\Controllers\Api\WorkshopController;
use Illuminate\Support\Facades\Route;

// ---- Khách (chưa đăng nhập) ----
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:register');
    Route::post('login', [AuthController::class, 'login']); // tự giới hạn theo email + IP trong controller
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:forgot-password');
    Route::post('resend-temp-password', [AuthController::class, 'resendTempPassword'])->middleware('throttle:forgot-password');

    // Token tạm nhận được khi đăng nhập bằng mật khẩu tạm
    Route::post('set-password', [AuthController::class, 'setPassword'])
        ->middleware(['auth:sanctum', 'abilities:'.AuthController::ABILITY_SET_PASSWORD]);
});

// Trình đọc sách (trang HTML cho WebView / iframe) + file sách qua link có chữ ký
Route::get('reader', [ReaderController::class, 'page'])->name('reader.page');
Route::get('reader/file/{id}/{kind}', [ReaderController::class, 'file'])
    ->whereNumber('id')->whereIn('kind', ['preview', 'full'])->middleware('signed')->name('reader.file');

Route::get('app-config', [AppConfigController::class, 'show']);
Route::get('referrals/{code}', [ReferralController::class, 'show'])->middleware('throttle:referral-check');

// Chiến dịch marketing: banner trên Home + trang chi tiết
Route::get('campaigns', [CampaignController::class, 'index']);
Route::get('campaigns/{id}', [CampaignController::class, 'show'])->whereNumber('id');

// Sự kiện ngoài lề: tin về sự kiện của đối tác bên ngoài (không đăng ký trong app)
Route::get('partner-events', [PartnerEventController::class, 'index']);
Route::get('partner-events/{id}', [PartnerEventController::class, 'show'])->whereNumber('id');

// Chương trình huấn luyện offline (nhiều buổi) + workshop online: danh sách + chi tiết.
// Token không bắt buộc; gửi kèm token app thì trả thêm "registered" (và link họp workshop khi tới giờ).
Route::get('trainings', [TrainingController::class, 'index']);
Route::get('trainings/{id}', [TrainingController::class, 'show'])->whereNumber('id');
Route::get('workshops', [WorkshopController::class, 'index']);
Route::get('workshops/{id}', [WorkshopController::class, 'show'])->whereNumber('id');

// Phát video tài liệu (HLS): link có chữ ký + hạn dùng, không cần header đăng nhập (xem HlsController)
Route::middleware('signed')->prefix('hls/{document}')->whereNumber('document')->group(function () {
    Route::get('master.m3u8', [HlsController::class, 'master'])->name('hls.master');
    Route::get('{height}/index.m3u8', [HlsController::class, 'variant'])->whereNumber('height')->name('hls.variant');
    Route::get('key', [HlsController::class, 'key'])->name('hls.key');
});
Route::middleware('signed')->prefix('hls/lesson-videos/{video}')->whereNumber('video')->group(function () {
    Route::get('master.m3u8', [HlsController::class, 'lessonMaster'])->name('hls.lesson.master');
    Route::get('{height}/index.m3u8', [HlsController::class, 'lessonVariant'])->whereNumber('height')->name('hls.lesson.variant');
    Route::get('key', [HlsController::class, 'lessonKey'])->name('hls.lesson.key');
});

// ---- Đã đăng nhập (token đầy đủ; token đổi mật khẩu không vào được) ----
Route::middleware(['auth:sanctum', 'abilities:app'])->group(function () {
    Route::get('me', [AuthController::class, 'me']);
    Route::get('user', [AuthController::class, 'me']); // giữ tương thích, dùng /me
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::patch('me', [ProfileController::class, 'update']);
    Route::post('me/avatar', [ProfileController::class, 'uploadAvatar'])->middleware('throttle:20,1');
    Route::delete('me/avatar', [ProfileController::class, 'deleteAvatar']);
    Route::post('me/password', [ProfileController::class, 'changePassword'])->middleware('throttle:change-password');
    Route::get('me/referrer/preview', [ProfileController::class, 'previewReferrer'])->middleware('throttle:referral-check');
    Route::post('me/referrer', [ProfileController::class, 'setReferrer'])->middleware('throttle:referral-check');
    Route::get('me/devices', [ProfileController::class, 'devices']);
    Route::get('me/coins', [ProfileController::class, 'coins']);
    Route::get('me/referrals', [ProfileController::class, 'referrals']);
    Route::delete('me/devices/{id}', [ProfileController::class, 'revokeDevice'])->whereNumber('id')->middleware('throttle:20,1');

    // Xem trước mã khuyến mãi khi thanh toán (giới hạn để chống dò mã)
    Route::post('promo-codes/check', [PromoCodeController::class, 'check'])->middleware('throttle:promo-code-check');

    // Lượt mở app (phiên 30 phút): gọi khi mở app, khi quay lại từ nền, và mỗi 60 giây khi đang mở
    Route::post('app-sessions/ping', [AppSessionController::class, 'ping'])->middleware('throttle:app-session-ping');

    // Khoá học của tôi + chọn lộ trình
    Route::get('my/courses', [MyCourseController::class, 'index']);
    Route::get('my/courses/{courseId}', [MyCourseController::class, 'show'])->whereNumber('courseId');
    Route::post('my/courses/{courseId}/path', [MyCourseController::class, 'choosePath'])->whereNumber('courseId');

    // Bài thi trong lộ trình
    Route::get('my/courses/{courseId}/exams/{chapterId}', [ExamController::class, 'show'])->whereNumber(['courseId', 'chapterId']);
    Route::post('my/courses/{courseId}/exams/{chapterId}/attempts', [ExamController::class, 'start'])->whereNumber(['courseId', 'chapterId'])->middleware('throttle:20,1');
    Route::get('exam-attempts/{id}', [ExamController::class, 'attempt'])->whereNumber('id');
    Route::post('exam-attempts/{id}/submit', [ExamController::class, 'submit'])->whereNumber('id')->middleware('throttle:20,1');

    // Học bài: nội dung, đã đọc hết, trả lời câu hỏi (server chấm)
    Route::get('lessons/{id}', [LessonController::class, 'show'])->whereNumber('id');
    Route::post('lessons/{id}/read', [LessonController::class, 'read'])->whereNumber('id');
    Route::post('lessons/{id}/quiz', [LessonController::class, 'answer'])->whereNumber('id')->middleware('throttle:60,1');
    Route::post('lessons/{id}/videos/{videoId}/play', [LessonController::class, 'playVideo'])->whereNumber(['id', 'videoId'])->middleware('throttle:60,1');
    // Theo dõi trải nghiệm học (thời gian, cuộn, video, đánh giá cuối bài)
    Route::post('lessons/{id}/sessions', [LessonTrackingController::class, 'start'])->whereNumber('id')->middleware('throttle:60,1');
    Route::patch('lesson-sessions/{id}', [LessonTrackingController::class, 'update'])->whereNumber('id')->middleware('throttle:120,1');
    Route::post('lessons/{id}/feedback', [LessonTrackingController::class, 'feedback'])->whereNumber('id')->middleware('throttle:30,1');

    // Khám phá khoá học (phân trang) + danh mục
    Route::get('courses', [CourseCatalogController::class, 'index']);
    Route::get('courses/categories', [CourseCatalogController::class, 'categories']);
    Route::get('courses/{id}', [CourseCatalogController::class, 'show'])->whereNumber('id');
    Route::post('courses/{id}/enroll', [CourseCatalogController::class, 'enroll'])->whereNumber('id')->middleware('throttle:30,1');

    // Giỏ hàng (khoá học, sách)
    Route::get('cart', [CartController::class, 'index']);
    Route::post('cart', [CartController::class, 'store'])->middleware('throttle:60,1');
    Route::post('cart/promo', [CartController::class, 'promo'])->middleware('throttle:promo-code-check');
    Route::delete('cart/{type}/{id}', [CartController::class, 'destroy'])->whereIn('type', ['course', 'document'])->whereNumber('id');

    // Tài liệu: Video (HLS), PDF, Sách
    Route::get('documents', [DocumentController::class, 'index']);
    Route::get('documents/topics', [DocumentController::class, 'topics']);
    Route::get('documents/{id}', [DocumentController::class, 'show'])->whereNumber('id');
    Route::post('documents/{id}/play', [DocumentController::class, 'play'])->whereNumber('id')->middleware('throttle:30,1');
    Route::post('documents/{id}/open', [DocumentController::class, 'open'])->whereNumber('id')->middleware('throttle:30,1');
    Route::post('documents/{id}/preview', [DocumentController::class, 'preview'])->whereNumber('id')->middleware('throttle:30,1');
    Route::post('documents/{id}/save', [DocumentController::class, 'save'])->whereNumber('id');
    Route::delete('documents/{id}/save', [DocumentController::class, 'unsave'])->whereNumber('id');
    Route::get('my/documents', [DocumentController::class, 'mine']);

    // Đăng ký chương trình huấn luyện (cả chương trình) / workshop (từng buổi). Cần gói thành viên còn hạn.
    Route::post('trainings/{id}/register', [TrainingController::class, 'register'])->whereNumber('id')->middleware('throttle:20,1');
    Route::delete('trainings/{id}/register', [TrainingController::class, 'cancel'])->whereNumber('id')->middleware('throttle:20,1');
    Route::get('my/trainings', [TrainingController::class, 'mine']);
    Route::post('workshop-sessions/{id}/register', [WorkshopController::class, 'register'])->whereNumber('id')->middleware('throttle:20,1');
    Route::delete('workshop-sessions/{id}/register', [WorkshopController::class, 'cancel'])->whereNumber('id')->middleware('throttle:20,1');
    Route::get('my/workshops', [WorkshopController::class, 'mine']);

    // Bài kiểm tra đầu vào: đề đang dùng, nộp bài (chấm tự động), kết quả gần nhất
    Route::get('placement-tests', [PlacementTestController::class, 'index']);
    Route::get('placement-tests/{id}', [PlacementTestController::class, 'show'])->whereNumber('id');
    Route::post('placement-tests/{id}/attempts', [PlacementTestController::class, 'submitTo'])->whereNumber('id')->middleware('throttle:10,1');
    Route::get('placement-tests/{id}/result', [PlacementTestController::class, 'resultOf'])->whereNumber('id');
    // Bản app cũ (một bài kiểm tra)
    Route::get('placement-test', [PlacementTestController::class, 'legacyShow']);
    Route::post('placement-test/attempts', [PlacementTestController::class, 'submit'])->middleware('throttle:10,1');
    Route::get('placement-test/result', [PlacementTestController::class, 'result']);
});
