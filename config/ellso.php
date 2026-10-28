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

    // Cho phép chạy Demo*Seeder trên production (chỉ để test trước khi launch). Launch: xoá biến này + reset DB.
    'allow_demo_seed' => (bool) env('ALLOW_DEMO_SEED', false),

    // Ảnh công khai (banner, ảnh bìa, icon, ảnh trong nội dung): public (local) | media_s3 (production). Xem App\Support\MediaStorage.
    'media' => [
        'disk' => env('MEDIA_DISK', 'public'),
    ],

    // Tài liệu: ổ lưu file (documents_local | documents_s3), thời hạn link, video HLS
    'documents' => [
        'disk' => env('DOCUMENTS_DISK', 'documents_local'),
        'link_ttl_minutes' => 5,          // link mở PDF / sách / bắt đầu phát video
        'video_max_upload_mb' => (int) env('DOCUMENTS_VIDEO_MAX_MB', 2048),
        'file_max_upload_mb' => (int) env('DOCUMENTS_FILE_MAX_MB', 200),
        'hls_variants' => [               // chiều cao => [chiều rộng, bitrate video, bitrate audio]
            480 => [854, '1000k', '96k'],
            720 => [1280, '2500k', '128k'],
        ],
        'hls_segment_seconds' => 6,
        'ffmpeg' => env('FFMPEG_BINARY', 'ffmpeg'),
        'ffprobe' => env('FFPROBE_BINARY', 'ffprobe'),
    ],
];
