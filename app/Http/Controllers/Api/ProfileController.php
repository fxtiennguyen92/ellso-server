<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserLogEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\UserLog;
use App\Support\MediaStorage;
use Illuminate\Http\Request;
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
        $path = MediaStorage::disk()->putFileAs("avatars/{$user->id}", $file, Str::random(24) . ".{$ext}", MediaStorage::isS3() ? [] : ['visibility' => 'public']);
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

    /** Chỉ xoá ảnh do app tải lên (thư mục avatars/), không đụng link ngoài. */
    private static function deleteStored(?string $path): void
    {
        if (is_string($path) && str_starts_with($path, 'avatars/')) {
            MediaStorage::disk()->delete($path);
        }
    }
}
