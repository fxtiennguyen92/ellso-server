<?php

namespace App\Services;

use App\Enums\TempPasswordReason;
use App\Enums\UserLogEvent;
use App\Mail\TempPasswordMail;
use App\Models\User;
use App\Models\UserLog;
use App\Support\DeviceInfo;
use App\Support\TempPassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Tạo và gửi mật khẩu tạm qua email.
 * Mật khẩu chính của user KHÔNG bị đổi: người khác bấm "quên mật khẩu" cũng không khóa được tài khoản.
 */
class TempPasswordService
{
    public function issue(
        User $user,
        TempPasswordReason $reason,
        ?Request $request = null,
        ?User $actor = null,
    ): void {
        $plain = TempPassword::generate();

        $expiresAt = $reason->expires()
            ? now()->addMinutes(config('ellso.auth.temp_password_ttl_minutes'))
            : null;

        // Lưu và gửi mail trong cùng transaction: gửi lỗi thì không lưu mật khẩu tạm "ma"
        DB::transaction(function () use ($user, $plain, $expiresAt, $reason, $request, $actor) {
            $user->forceFill([
                'temp_password' => $plain,
                'temp_password_expires_at' => $expiresAt,
                'temp_password_sent_at' => now(),
            ])->save();

            UserLog::record($user, UserLogEvent::TempPasswordSent, $request, [
                'reason' => $reason->value,
                'expires_at' => $expiresAt?->toIso8601String(),
            ], $actor);

            // Quên mật khẩu do chính user yêu cầu: ghi kèm thời điểm + thiết bị để họ nhận ra yêu cầu lạ
            $requestInfo = $reason === TempPasswordReason::Forgot && $request
                ? [
                    'time' => now()->timezone('Asia/Ho_Chi_Minh')->format('H:i, d/m/Y'),
                    'device' => DeviceInfo::summary($request),
                ]
                : null;

            Mail::to($user->email)->send(new TempPasswordMail($user, $plain, $reason, $expiresAt, $requestInfo));
        });
    }
}
