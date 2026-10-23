<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Lý do gửi mật khẩu tạm — quyết định hạn dùng và nội dung email. */
enum TempPasswordReason: string implements HasLabel
{
    case Register = 'register';           // user tự đăng ký
    case RegisterResend = 'register_resend'; // user bấm "gửi lại" ở màn đã đăng ký (chưa đăng nhập lần đầu)
    case AdminCreated = 'admin_created';  // admin tạo tài khoản trên CMS
    case AdminResend = 'admin_resend';    // admin gửi lại khi user chưa đăng nhập lần đầu
    case Forgot = 'forgot';               // user bấm quên mật khẩu
    case AdminReset = 'admin_reset';      // admin reset (tương đương quên mật khẩu)

    public function getLabel(): string
    {
        return match ($this) {
            self::Register => 'Đăng ký',
            self::RegisterResend => 'Gửi lại sau đăng ký',
            self::AdminCreated => 'Admin tạo tài khoản',
            self::AdminResend => 'Admin gửi lại',
            self::Forgot => 'Quên mật khẩu',
            self::AdminReset => 'Admin reset mật khẩu',
        };
    }

    /** Mật khẩu tạm kích hoạt tài khoản không hết hạn; mật khẩu tạm khôi phục có hạn. */
    public function expires(): bool
    {
        return in_array($this, [self::Forgot, self::AdminReset], true);
    }

    public function isActivation(): bool
    {
        return ! $this->expires();
    }
}
