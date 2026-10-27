<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nội dung email chỉnh trên CMS + thông tin công ty / email hỗ trợ dùng ở chân trang email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();      // do code định nghĩa, không đổi
            $table->string('name');                   // tên hiển thị trên CMS
            $table->text('description')->nullable();  // gửi khi nào

            // Các phần admin sửa được. Hỗ trợ biến {ten}, {email}… và **in đậm**.
            $table->string('subject');
            $table->string('preheader')->nullable();  // dòng xem trước trong hộp thư
            $table->string('eyebrow')->nullable();
            $table->string('heading');
            $table->text('intro');
            $table->string('steps_title')->nullable();
            $table->json('steps')->nullable();        // danh sách các bước, đánh số tự động
            $table->text('after_text')->nullable();
            $table->text('note')->nullable();         // ghi chú an toàn (chữ nhỏ)
            $table->text('sign_off')->nullable();
            $table->text('footer_reason')->nullable(); // "Bạn nhận được email này vì…"

            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();

        DB::table('email_templates')->insert([
            [
                'key' => 'temp_password_activation',
                'name' => 'Mật khẩu tạm – kích hoạt tài khoản',
                'description' => 'Gửi khi học viên đăng ký, khi admin tạo tài khoản, và khi gửi lại mật khẩu tạm cho người chưa đăng nhập lần đầu.',
                'subject' => 'Mật khẩu đăng nhập lần đầu của bạn tại Ellso',
                'preheader' => 'Mật khẩu tạm để bắt đầu học cùng Ellso',
                'eyebrow' => 'Kích hoạt tài khoản',
                'heading' => 'Chào mừng bạn đến với Ellso',
                'intro' => "Xin chào {ten},\nTài khoản Ellso của bạn đã được tạo với email **{email}**. Dùng mật khẩu tạm dưới đây để đăng nhập lần đầu.",
                'steps_title' => 'Chỉ 3 bước để bắt đầu',
                'steps' => json_encode([
                    'Mở ứng dụng Ellso trên điện thoại.',
                    'Đăng nhập bằng email và mật khẩu tạm ở trên.',
                    'Đặt mật khẩu mới của riêng bạn — bạn sẽ vào học ngay sau đó.',
                ], JSON_UNESCAPED_UNICODE),
                'after_text' => null,
                'note' => 'Vì an toàn, đừng chia sẻ mật khẩu này với ai. Ellso không bao giờ hỏi mật khẩu của bạn qua điện thoại hay tin nhắn.',
                'sign_off' => "Chúc bạn học tốt,\nĐội ngũ Ellso",
                'footer_reason' => 'Bạn nhận được email này vì địa chỉ {email} vừa được dùng để tạo tài khoản Ellso.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'temp_password_reset',
                'name' => 'Mật khẩu tạm – quên mật khẩu',
                'description' => 'Gửi khi học viên bấm Quên mật khẩu hoặc admin bấm Reset mật khẩu. Mật khẩu tạm có hạn dùng.',
                'subject' => 'Mật khẩu tạm để đăng nhập lại Ellso',
                'preheader' => 'Mật khẩu tạm có hiệu lực trong {so_phut} phút',
                'eyebrow' => 'Đặt lại mật khẩu',
                'heading' => 'Mật khẩu tạm để đăng nhập lại',
                'intro' => "Xin chào {ten},\nChúng tôi vừa nhận được yêu cầu cấp lại mật khẩu cho tài khoản **{email}**. Đây là mật khẩu tạm của bạn:",
                'steps_title' => null,
                'steps' => null,
                'after_text' => 'Đăng nhập ứng dụng Ellso bằng mật khẩu này, sau đó đặt mật khẩu mới. Mật khẩu hiện tại của bạn vẫn dùng được cho đến khi bạn đổi.',
                'note' => 'Không phải bạn yêu cầu? Bạn có thể bỏ qua email này — tài khoản của bạn vẫn an toàn và mật khẩu tạm sẽ tự hết hạn.',
                'sign_off' => "Trân trọng,\nĐội ngũ Ellso",
                'footer_reason' => 'Bạn nhận được email này vì có yêu cầu đặt lại mật khẩu cho {email} trên Ellso.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        DB::table('system_settings')->insert([
            [
                'key' => 'support_email',
                'value' => 'cskh@ellso.edu.vn',
                'type' => 'email',
                'group' => 'company',
                'label' => 'Email hỗ trợ khách hàng',
                'unit' => null,
                'description' => 'Hiện ở chân trang email và là địa chỉ nhận khi người dùng bấm Trả lời.',
                'sort_order' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'company_name',
                'value' => null,
                'type' => 'string',
                'group' => 'company',
                'label' => 'Tên công ty',
                'unit' => null,
                'description' => 'Hiện ở chân trang email. Để trống thì không hiện.',
                'sort_order' => 11,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'company_address',
                'value' => null,
                'type' => 'string',
                'group' => 'company',
                'label' => 'Địa chỉ công ty',
                'unit' => null,
                'description' => 'Hiện ở chân trang email (giúp email ít bị đánh dấu spam). Để trống thì không hiện.',
                'sort_order' => 12,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        Cache::forget('system_settings'); // SystemSetting::get() cache
    }

    public function down(): void
    {
        DB::table('system_settings')->whereIn('key', ['support_email', 'company_name', 'company_address'])->delete();
        Cache::forget('system_settings');
        Schema::dropIfExists('email_templates');
    }
};
