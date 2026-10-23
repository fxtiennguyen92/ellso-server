<?php

return [

    'auth' => [
        // Hạn dùng mật khẩu tạm khi quên mật khẩu / admin reset (phút).
        // Mật khẩu tạm lúc đăng ký / admin gửi lại không hết hạn.
        'temp_password_ttl_minutes' => (int) env('TEMP_PASSWORD_TTL_MINUTES', 30),

        // Token chỉ dùng để đổi mật khẩu sau khi đăng nhập bằng mật khẩu tạm (phút).
        'password_change_token_ttl_minutes' => 15,

        // Chống spam quên mật khẩu
        'forgot_password' => [
            'cooldown_seconds' => 120,   // tối thiểu giữa 2 lần gửi cho cùng 1 email
            'max_per_email_per_day' => 5,
            'max_per_ip_per_hour' => 10,
        ],

        // Đăng nhập sai: khóa tạm theo email + IP
        'login_max_attempts_per_minute' => 5,

        'register_max_per_ip_per_hour' => 10,
        'referral_check_max_per_ip_per_minute' => 20,
    ],

];
