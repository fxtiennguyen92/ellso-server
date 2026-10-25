<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - app_sessions: lượt mở app (phiên 30 phút) — nguồn số liệu cho Thống kê › Hoạt động học viên.
 * - student_memberships: gói học viên (thời hạn 1 năm) — mở tài liệu và khoá huấn luyện.
 * - Cấu hình giá + thời hạn gói học viên.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('device_id', 100)->nullable();
            $table->string('device_name')->nullable();
            $table->string('platform', 20)->nullable();   // ios | android | web
            $table->string('os_version', 50)->nullable();
            $table->string('app_version', 50)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('started_at');               // thời điểm mở app
            $table->timestamp('last_seen_at');             // tín hiệu cuối cùng (heartbeat) → thời lượng dùng
            $table->unsignedInteger('ping_count')->default(1);

            $table->index('started_at');
            $table->index(['user_id', 'device_id', 'last_seen_at']);
        });

        Schema::create('student_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->index();
            $table->unsignedBigInteger('price')->default(0);     // giá đã trả (VND)
            $table->string('source', 20)->default('purchase');   // purchase | admin | gift …
            $table->unsignedBigInteger('order_id')->nullable()->index(); // nối khoá ngoại khi có bảng orders
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'ends_at']);
        });

        $now = now();

        DB::table('system_settings')->insert([
            [
                'key' => 'student_membership_price',
                'value' => null,
                'type' => 'integer',
                'group' => 'membership',
                'label' => 'Giá gói học viên',
                'unit' => 'VND',
                'description' => 'Giá bán gói học viên: xem tài liệu và tham gia các khoá huấn luyện trong thời hạn gói.',
                'sort_order' => 5,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'student_membership_months',
                'value' => '12',
                'type' => 'integer',
                'group' => 'membership',
                'label' => 'Thời hạn gói học viên',
                'unit' => 'tháng',
                'description' => 'Số tháng gói học viên có hiệu lực kể từ ngày mua.',
                'sort_order' => 6,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        Cache::forget('system_settings');
    }

    public function down(): void
    {
        DB::table('system_settings')->whereIn('key', ['student_membership_price', 'student_membership_months'])->delete();
        Cache::forget('system_settings');

        Schema::dropIfExists('student_memberships');
        Schema::dropIfExists('app_sessions');
    }
};
