<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Đăng ký / đăng nhập / quên mật khẩu cho app, mã giới thiệu, nhật ký người dùng và cấu hình hệ thống.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Mã giới thiệu riêng của mỗi user: 8 ký tự A-Z0-9
            $table->char('referral_code', 8)->nullable()->unique()->after('email');
            // Người đã giới thiệu user này
            $table->foreignId('referred_by_id')->nullable()->after('referral_code')
                ->constrained('users')->nullOnDelete();

            // Mật khẩu tạm (hash) gửi qua email. Hạn dùng null = không hết hạn (mật khẩu tạm lúc đăng ký).
            $table->string('temp_password')->nullable()->after('password');
            $table->timestamp('temp_password_expires_at')->nullable()->after('temp_password');
            $table->timestamp('temp_password_sent_at')->nullable()->after('temp_password_expires_at');
            // null = user chưa tự đặt mật khẩu (chưa kích hoạt / chưa đăng nhập lần đầu)
            $table->timestamp('password_changed_at')->nullable()->after('temp_password_sent_at');

            $table->timestamp('last_login_at')->nullable()->after('password_changed_at');
        });

        // User có sẵn: coi như đã có mật khẩu riêng, và cấp mã giới thiệu
        DB::table('users')->whereNull('password_changed_at')->update(['password_changed_at' => DB::raw('created_at')]);

        // Bỏ 0/O/1/I cho dễ đọc, dễ gõ (giống App\Support\ReferralCode)
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $used = [];

        foreach (DB::table('users')->whereNull('referral_code')->orderBy('id')->pluck('id') as $id) {
            do {
                $code = '';
                for ($i = 0; $i < 8; $i++) {
                    $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
                }
            } while (isset($used[$code]) || DB::table('users')->where('referral_code', $code)->exists());

            $used[$code] = true;
            DB::table('users')->where('id', $id)->update(['referral_code' => $code]);
        }

        Schema::create('user_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Admin thực hiện thao tác (gửi lại / reset mật khẩu); null = chính user
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 40)->index();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('device_name')->nullable();   // VD: iPhone 15 Pro
            $table->string('platform', 20)->nullable();  // ios | android | web
            $table->string('os_version', 50)->nullable();
            $table->string('app_version', 50)->nullable();
            $table->string('device_id', 100)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value')->nullable();
            $table->string('type', 20)->default('string'); // integer | decimal | string | boolean | json
            $table->string('group', 50)->default('general');
            $table->string('label');
            $table->string('unit', 20)->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();

        DB::table('system_settings')->insert([
            [
                'key' => 'referral_commission_percent',
                'value' => '10',
                'type' => 'decimal',
                'group' => 'referral',
                'label' => 'Tỉ lệ hoa hồng người giới thiệu',
                'unit' => '%',
                'description' => 'Phần trăm người giới thiệu được hưởng trên giá trị đơn hàng của người được giới thiệu.',
                'sort_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'credit_to_vnd_rate',
                'value' => '1000',
                'type' => 'integer',
                'group' => 'credit',
                'label' => 'Tỉ lệ quy đổi xu sang VND',
                'unit' => 'VND',
                'description' => 'Số tiền VND tương ứng với 1 xu (credit).',
                'sort_order' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('user_logs');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referred_by_id');
            $table->dropUnique(['referral_code']);
            $table->dropColumn([
                'referral_code',
                'temp_password',
                'temp_password_expires_at',
                'temp_password_sent_at',
                'password_changed_at',
                'last_login_at',
            ]);
        });
    }
};
