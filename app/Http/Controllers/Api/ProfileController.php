<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserLogEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\UserLog;
use App\Support\MediaStorage;
use App\Support\ReferralCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Hồ sơ của học viên đang đăng nhập (app › Hồ sơ):
 * - PATCH  /me           : sửa họ tên, số điện thoại (email không đổi ở đây).
 * - POST   /me/avatar    : ảnh đại diện (multipart "avatar"; app đã cắt vuông + thu nhỏ ~512px trước khi gửi).
 * - DELETE /me/avatar    : bỏ ảnh, quay về chữ viết tắt.
 * - POST   /me/password  : đổi mật khẩu = mật khẩu hiện tại + mật khẩu mới (cùng quy tắc với đặt mật khẩu);
 *                          đổi xong đăng xuất mọi thiết bị khác, thiết bị này giữ đăng nhập.
 */
class ProfileController extends Controller
{
    public function update(Request $request): UserResource
    {
        $request->merge([
            'name' => trim((string) $request->input('name')),
            'phone' => RegisterRequest::normalizePhone($request->input('phone')),
        ]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'regex:/^0\d{9}$/'],
        ], [
            'name.required' => 'Vui lòng nhập họ tên.',
            'name.max' => 'Họ tên quá dài.',
            'phone.regex' => 'Số điện thoại không hợp lệ (10 số, bắt đầu bằng 0).',
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->update(['name' => $data['name'], 'phone' => $data['phone'] ?? null]);

        return new UserResource($user->load('referrer'));
    }

    public function uploadAvatar(Request $request): UserResource
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:min_width=64,min_height=64,max_width=4096,max_height=4096'],
        ], [
            'avatar.required' => 'Chưa chọn ảnh.',
            'avatar.image' => 'File không phải ảnh.',
            'avatar.mimes' => 'Chỉ nhận ảnh JPG, PNG hoặc WebP.',
            'avatar.max' => 'Ảnh tối đa 5 MB.',
            'avatar.dimensions' => 'Ảnh quá nhỏ hoặc quá lớn.',
        ]);

        /** @var User $user */
        $user = $request->user();
        $file = $request->file('avatar');
        $ext = strtolower($file->guessExtension() ?: 'jpg');
        // Tên ngẫu nhiên mỗi lần đổi → link mới, không dính cache ảnh cũ (CDN cache lâu)
        // S3 (BizFly): quyền đọc công khai theo ACL cấu hình sẵn của ổ media; local: cần visibility public
        $path = MediaStorage::disk()->putFileAs("avatars/{$user->id}", $file, Str::random(24).".{$ext}", MediaStorage::isS3() ? [] : ['visibility' => 'public']);
        if (! $path) {
            abort(500, 'Không lưu được ảnh, vui lòng thử lại.');
        }

        $old = $user->avatar;
        $user->update(['avatar' => $path]);
        self::deleteStored($old);

        return new UserResource($user->load('referrer'));
    }

    public function deleteAvatar(Request $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();
        $old = $user->avatar;
        $user->update(['avatar' => null]);
        self::deleteStored($old);

        return new UserResource($user->load('referrer'));
    }

    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', 'max:100', Password::min(8)->mixedCase()->numbers()],
        ], [
            'current_password.required' => 'Vui lòng nhập mật khẩu hiện tại.',
            'password.required' => 'Vui lòng nhập mật khẩu mới.',
            'password.confirmed' => 'Mật khẩu nhập lại không khớp.',
            'password.min' => 'Mật khẩu phải có ít nhất 8 ký tự.',
            'password.max' => 'Mật khẩu quá dài.',
            'password.mixed' => 'Mật khẩu phải có cả chữ hoa và chữ thường.',
            'password.numbers' => 'Mật khẩu phải có ít nhất 1 chữ số.',
        ]);

        /** @var User $user */
        $user = $request->user();
        if (! Hash::check((string) $request->input('current_password'), (string) $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Mật khẩu hiện tại không đúng.']);
        }
        if (Hash::check((string) $request->input('password'), (string) $user->password)) {
            throw ValidationException::withMessages(['password' => 'Mật khẩu mới phải khác mật khẩu hiện tại.']);
        }

        $user->setOwnPassword((string) $request->input('password'));

        // Đăng xuất mọi thiết bị khác; thiết bị đang dùng giữ đăng nhập
        $current = $user->currentAccessToken();
        $user->tokens()->when($current?->getKey(), fn ($q, $id) => $q->whereKeyNot($id))->delete();

        UserLog::record($user, UserLogEvent::PasswordChanged, $request, ['source' => 'app_profile']);

        return response()->json(['message' => 'Đã đổi mật khẩu.']);
    }

    /**
     * GET /me/referrer/preview?code= — xem trước người giới thiệu (tên + email) trước khi xác nhận thêm.
     * Cần đăng nhập (khác GET /referrals/{code} công khai chỉ trả tên).
     */
    public function previewReferrer(Request $request): JsonResponse
    {
        $referrer = $this->resolveReferrer($request);

        return response()->json(['data' => [
            'name' => $referrer->name,
            'email' => $referrer->email,
            'referral_code' => $referrer->referral_code,
        ]]);
    }

    /** POST /me/referrer {code} — thêm người giới thiệu sau khi đăng ký. Chỉ một lần, không đổi được. */
    public function setReferrer(Request $request): UserResource
    {
        $referrer = $this->resolveReferrer($request);

        /** @var User $user */
        $user = $request->user();
        $updated = DB::transaction(function () use ($user, $referrer) {
            // khoá dòng để 2 yêu cầu cùng lúc không ghi đè nhau
            $fresh = User::query()->whereKey($user->getKey())->lockForUpdate()->first();
            if ($fresh->referred_by_id !== null) {
                return false;
            }
            $fresh->forceFill(['referred_by_id' => $referrer->getKey()])->save();

            return true;
        });

        if (! $updated) {
            throw ValidationException::withMessages(['code' => 'Tài khoản đã có người giới thiệu, không thể thay đổi.']);
        }

        UserLog::record($user, UserLogEvent::ReferrerAdded, $request, ['referral_code' => $referrer->referral_code, 'source' => 'app_profile']);

        return new UserResource($user->refresh()->load('referrer'));
    }

    private function resolveReferrer(Request $request): User
    {
        $request->validate(['code' => ['required', 'string', 'max:32']], ['code.required' => 'Vui lòng nhập mã giới thiệu.']);

        /** @var User $user */
        $user = $request->user();
        if ($user->referred_by_id !== null) {
            throw ValidationException::withMessages(['code' => 'Tài khoản đã có người giới thiệu, không thể thay đổi.']);
        }

        $code = ReferralCode::normalize((string) $request->input('code'));
        $referrer = $code
            ? User::query()->where('referral_code', $code)->where('is_active', true)->first()
            : null;

        if (! $referrer) {
            throw ValidationException::withMessages(['code' => 'Mã giới thiệu không hợp lệ.']);
        }
        if ($referrer->is($user)) {
            throw ValidationException::withMessages(['code' => 'Không thể dùng mã giới thiệu của chính bạn.']);
        }
        if ($referrer->referred_by_id === $user->getKey()) {
            throw ValidationException::withMessages(['code' => 'Người này được bạn giới thiệu nên không thể là người giới thiệu của bạn.']);
        }

        return $referrer;
    }

    /**
     * GET /me/devices — các thiết bị đang đăng nhập (mỗi token đăng nhập = một thiết bị), thiết bị đang dùng đứng đầu,
     * còn lại theo lần dùng gần nhất. Không gồm token tạm đổi mật khẩu lần đầu.
     */
    public function devices(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $currentId = $user->currentAccessToken()?->getKey();

        $rows = $user->tokens()
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get()
            ->reject(fn ($t) => in_array(AuthController::ABILITY_SET_PASSWORD, (array) $t->abilities, true))
            ->sortByDesc(fn ($t) => [$t->getKey() === $currentId ? 1 : 0, ($t->last_used_at ?? $t->created_at)?->getTimestamp()])
            ->values()
            ->map(fn ($t) => [
                'id' => $t->getKey(),
                'name' => $t->name,
                'platform' => $t->platform,
                'os_version' => $t->os_version,
                'app_version' => $t->app_version,
                'last_used_at' => ($t->last_used_at ?? $t->created_at)?->toIso8601String(),
                'created_at' => $t->created_at?->toIso8601String(),
                'current' => $t->getKey() === $currentId,
            ]);

        return response()->json(['data' => $rows]);
    }

    /** DELETE /me/devices/{id} — đăng xuất tài khoản trên thiết bị đó (xoá token). Thiết bị đang dùng → dùng Đăng xuất. */
    public function revokeDevice(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->currentAccessToken()?->getKey() === $id) {
            return response()->json(['message' => 'Đây là thiết bị bạn đang dùng. Hãy dùng Đăng xuất trong Hồ sơ.', 'code' => 'current_device'], 422);
        }

        $token = $user->tokens()->whereKey($id)->first();
        if (! $token) {
            return response()->json(['message' => 'Thiết bị không còn đăng nhập.', 'code' => 'not_found'], 404);
        }
        $token->delete();

        UserLog::record($user, UserLogEvent::Logout, $request, ['source' => 'app_devices', 'token_id' => $id, 'device' => $token->name]);

        return response()->json(['message' => 'Đã đăng xuất thiết bị.']);
    }

    /**
     * GET /me/coins?cursor= — số dư, xu nhận / dùng trong tháng (giờ VN), lịch sử mới nhất trước, 20 dòng / trang
     * (cursor = next_cursor của trang trước).
     */
    public function coins(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $page = $user->coinTransactions()->orderByDesc('id')->cursorPaginate(20);

        // Tháng hiện tại theo giờ Việt Nam
        $from = now('Asia/Ho_Chi_Minh')->startOfMonth()->utc();
        $month = $user->coinTransactions()->where('created_at', '>=', $from);

        return response()->json([
            'balance' => (int) $user->coin_balance,
            'earned_month' => (int) (clone $month)->where('amount', '>', 0)->sum('amount'),
            'spent_month' => (int) abs((clone $month)->where('amount', '<', 0)->sum('amount')),
            'data' => collect($page->items())->map(fn ($t) => [
                'id' => $t->id,
                'amount' => $t->amount,
                'balance_after' => $t->balance_after,
                'source' => $t->source->value,
                'source_label' => $t->source->getLabel(),
                'description' => $t->description,
                'created_at' => $t->created_at?->toIso8601String(),
            ]),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    /**
     * GET /me/referrals?cursor= — bạn bè mình đã giới thiệu (đăng ký bằng mã của mình hoặc thêm mình làm người giới thiệu),
     * mới nhất trước, 20 / trang. Chỉ trả tên, ảnh, ngày tham gia (không lộ email / SĐT của bạn bè).
     */
    public function referrals(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $page = $user->referrals()->orderByDesc('id')->cursorPaginate(20, ['id', 'name', 'avatar', 'created_at']);

        return response()->json([
            'total' => $user->referrals()->count(),
            'data' => collect($page->items())->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'avatar' => $u->avatarUrl(),
                'joined_at' => $u->created_at?->toIso8601String(),
            ]),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    /** Chỉ xoá ảnh do app tải lên (thư mục avatars/), không đụng link ngoài. */
    private static function deleteStored(?string $path): void
    {
        if (is_string($path) && str_starts_with($path, 'avatars/')) {
            MediaStorage::disk()->delete($path);
        }
    }
}
