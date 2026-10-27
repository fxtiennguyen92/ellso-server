<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mẫu email mời tham gia: admin tạo tài khoản cho người dùng trên CMS và gửi lời mời kèm mật khẩu tạm.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('email_templates')->insert([
            'key' => 'temp_password_invite',
            'name' => 'Lời mời tham gia',
            'description' => 'Gửi khi admin tạo tài khoản và mời người dùng tham gia (nút "Mời tham gia"), và khi gửi lại lời mời cho người chưa đăng nhập lần đầu.',
            'subject' => '{ten} ơi, Ellso đã tạo tài khoản cho bạn',
            'preheader' => 'Mời bạn tham gia cùng cộng đồng Ellso',
            'eyebrow' => 'Lời mời tham gia',
            'heading' => 'Ellso đã tạo tài khoản cho bạn',
            'intro' => "Xin chào {ten},\nĐội ngũ Ellso đã tạo sẵn tài khoản cho bạn với email **{email}**. Mời bạn tham gia cùng cộng đồng Ellso để học về đổi mới tư duy và lãnh đạo.",
            'steps_title' => 'Bắt đầu chỉ với 3 bước',
            'steps' => json_encode([
                'Tải và mở ứng dụng Ellso trên điện thoại.',
                'Đăng nhập bằng email và mật khẩu tạm ở trên.',
                'Đặt mật khẩu mới của riêng bạn — bạn sẽ vào học ngay sau đó.',
            ], JSON_UNESCAPED_UNICODE),
            'after_text' => null,
            'note' => 'Nếu bạn không muốn tham gia, chỉ cần bỏ qua email này. Vì an toàn, đừng chia sẻ mật khẩu tạm với ai.',
            'sign_off' => "Rất mong được đồng hành cùng bạn,\nĐội ngũ Ellso",
            'footer_reason' => 'Bạn nhận được email này vì đội ngũ Ellso đã tạo tài khoản cho địa chỉ {email}.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('email_templates')->where('key', 'temp_password_invite')->delete();
    }
};
