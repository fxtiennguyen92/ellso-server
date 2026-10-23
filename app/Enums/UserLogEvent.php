<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum UserLogEvent: string implements HasLabel, HasColor
{
    case Registered = 'registered';
    case Login = 'login';
    case LoginWithTempPassword = 'login_temp_password';
    case LoginFailed = 'login_failed';
    case LoginBlocked = 'login_blocked';
    case Logout = 'logout';
    case PasswordChanged = 'password_changed';
    case TempPasswordSent = 'temp_password_sent';
    case ForgotPasswordThrottled = 'forgot_password_throttled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Registered => 'Đăng ký',
            self::Login => 'Đăng nhập',
            self::LoginWithTempPassword => 'Đăng nhập bằng mật khẩu tạm',
            self::LoginFailed => 'Đăng nhập thất bại',
            self::LoginBlocked => 'Đăng nhập khi tài khoản bị khóa',
            self::Logout => 'Đăng xuất',
            self::PasswordChanged => 'Đổi mật khẩu',
            self::TempPasswordSent => 'Gửi mật khẩu tạm',
            self::ForgotPasswordThrottled => 'Quên mật khẩu bị chặn (spam)',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Registered, self::Login => 'success',
            self::LoginWithTempPassword, self::PasswordChanged, self::TempPasswordSent => 'info',
            self::LoginFailed, self::LoginBlocked, self::ForgotPasswordThrottled => 'danger',
            self::Logout => 'gray',
        };
    }
}
