<?php

namespace App\Http\Controllers\Api;

use App\Enums\TempPasswordReason;
use App\Enums\UserLogEvent;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\SetPasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\UserLog;
use App\Services\TempPasswordService;
use App\Support\DeviceInfo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Luồng tài khoản cho app.
 *
 * Đăng ký → email nhận mật khẩu tạm → đăng nhập bằng mật khẩu tạm → nhận change_token
 * → đặt mật khẩu mới (gửi kèm change_token) → nhận token đăng nhập luôn, không phải đăng nhập lại.
 *
 * Thông tin thiết bị gửi qua header (xem App\Support\DeviceInfo).
 */
class AuthController extends Controller
{
    /** Ability của token chỉ được phép đặt mật khẩu mới. */
    public const ABILITY_SET_PASSWORD = 'password:change';

    public function __construct(private TempPasswordService $tempPasswords) {}

    /** POST /auth/register */
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $request) {
            $referrer = filled($data['referral_code'] ?? null)
                ? User::query()->where('referral_code', $data['referral_code'])->first()
                : null;

            $user = new User([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'role' => UserRole::Student,
                'is_active' => true,
            ]);
            // Mật khẩu chính ngẫu nhiên, không ai biết: user đăng nhập lần đầu bằng mật khẩu tạm
            $user->password = Str::random(64);
            $user->referred_by_id = $referrer?->getKey();
            $user->save();

            UserLog::record($user, UserLogEvent::Registered, $request, array_filter([
                'referral_code' => $referrer?->referral_code,
            ]));

            $this->tempPasswords->issue($user, TempPasswordReason::Register, $request);
        });

        return response()->json([
            'message' => 'Đăng ký thành công. Mật khẩu tạm đã được gửi tới email của bạn.',
            'email' => $data['email'],
        ], 201);
    }

    /** POST /auth/login */
    public function login(Request $request): JsonResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ], [
            'email.required' => 'Vui lòng nhập email.',
            'email.email' => 'Email không hợp lệ.',
            'password.required' => 'Vui lòng nhập mật khẩu.',
        ]);

        $email = $request->input('email');
        $password = (string) $request->input('password');
        $throttleKey = 'login:' . $email . '|' . $request->ip();
        $maxAttempts = config('ellso.auth.login_max_attempts_per_minute');

        if (RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            return $this->tooManyAttempts(RateLimiter::availableIn($throttleKey));
        }

        $user = User::query()->where('email', $email)->first();

        $usingPassword = $user?->checkPassword($password) ?? false;
        $usingTemp = ! $usingPassword && ($user?->checkTempPassword($password) ?? false);

        if (! $usingPassword && ! $usingTemp) {
            RateLimiter::hit($throttleKey, 60);

            if ($user) {
                UserLog::record($user, UserLogEvent::LoginFailed, $request);
            }

            throw ValidationException::withMessages(['email' => 'Email hoặc mật khẩu không đúng.']);
        }

        RateLimiter::clear($throttleKey);

        if (! $user->is_active) {
            UserLog::record($user, UserLogEvent::LoginBlocked, $request);

            return response()->json(['message' => 'Tài khoản của bạn đã bị khóa. Vui lòng liên hệ hỗ trợ.'], 403);
        }

        if ($usingTemp) {
            UserLog::record($user, UserLogEvent::LoginWithTempPassword, $request);

            $ttl = config('ellso.auth.password_change_token_ttl_minutes');
            $changeToken = $user->createToken('password-change', [self::ABILITY_SET_PASSWORD], now()->addMinutes($ttl));

            return response()->json([
                'requires_password_change' => true,
                'message' => 'Vui lòng đặt mật khẩu mới để tiếp tục.',
                'change_token' => $changeToken->plainTextToken,
                'expires_in' => $ttl * 60,
            ]);
        }

        // Đăng nhập bằng mật khẩu chính → mật khẩu tạm đang chờ (nếu có) không còn cần thiết
        if ($user->temp_password !== null) {
            $user->forceFill(['temp_password' => null, 'temp_password_expires_at' => null])->save();
        }

        return $this->completeLogin($user, $request);
    }

    /** POST /auth/set-password — dùng change_token nhận được khi đăng nhập bằng mật khẩu tạm. */
    public function setPassword(SetPasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $token = $user->currentAccessToken();

        // Chỉ token đổi mật khẩu mới dùng được endpoint này (token đăng nhập thường có ability "*")
        if ($token->abilities !== [self::ABILITY_SET_PASSWORD]) {
            return response()->json(['message' => 'Không có quyền thực hiện thao tác này.'], 403);
        }

        $password = $request->validated('password');

        if ($user->temp_password !== null && Hash::check($password, $user->temp_password)) {
            throw ValidationException::withMessages(['password' => 'Mật khẩu mới phải khác mật khẩu tạm.']);
        }

        $user->setOwnPassword($password);
        $token->delete();

        UserLog::record($user, UserLogEvent::PasswordChanged, $request);

        return $this->completeLogin($user, $request);
    }

    /** POST /auth/forgot-password — luôn trả cùng một câu trả lời để không lộ email nào đã đăng ký. */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $request->validate(['email' => ['required', 'email']], [
            'email.required' => 'Vui lòng nhập email.',
            'email.email' => 'Email không hợp lệ.',
        ]);

        $cooldown = config('ellso.auth.forgot_password.cooldown_seconds');
        $response = response()->json([
            'message' => 'Nếu email đã đăng ký, mật khẩu tạm sẽ được gửi tới hộp thư của bạn trong ít phút.',
            'resend_after_seconds' => $cooldown,
        ]);

        $user = User::query()->where('email', $request->input('email'))->where('is_active', true)->first();

        if (! $user) {
            return $response;
        }

        // Chống spam theo email: khoảng cách tối thiểu giữa 2 lần gửi + tối đa N lần / ngày
        $dailyKey = 'forgot-password:day:' . $user->getKey();
        $inCooldown = $user->temp_password_sent_at?->gt(now()->subSeconds($cooldown)) ?? false;
        $overDaily = RateLimiter::tooManyAttempts($dailyKey, config('ellso.auth.forgot_password.max_per_email_per_day'));

        if ($inCooldown || $overDaily) {
            UserLog::record($user, UserLogEvent::ForgotPasswordThrottled, $request, [
                'reason' => $inCooldown ? 'cooldown' : 'daily_limit',
            ]);

            return $response;
        }

        RateLimiter::hit($dailyKey, 86400);
        $this->tempPasswords->issue($user, TempPasswordReason::Forgot, $request);

        return $response;
    }

    /** POST /auth/logout — thu hồi token của thiết bị hiện tại. */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->currentAccessToken()->delete();

        UserLog::record($user, UserLogEvent::Logout, $request);

        return response()->json(['message' => 'Đã đăng xuất.']);
    }

    /** GET /me */
    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('referrer'));
    }

    private function completeLogin(User $user, Request $request): JsonResponse
    {
        $token = $user->createToken(DeviceInfo::tokenName($request));

        $user->forceFill(['last_login_at' => now()])->save();

        UserLog::record($user, UserLogEvent::Login, $request, [
            'token_id' => $token->accessToken->getKey(),
        ]);

        return response()->json([
            'requires_password_change' => false,
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'user' => new UserResource($user->load('referrer')),
        ]);
    }

    private function tooManyAttempts(int $seconds): JsonResponse
    {
        return response()->json([
            'message' => "Bạn đã thử quá nhiều lần. Vui lòng thử lại sau {$seconds} giây.",
            'retry_after' => $seconds,
        ], 429);
    }
}
