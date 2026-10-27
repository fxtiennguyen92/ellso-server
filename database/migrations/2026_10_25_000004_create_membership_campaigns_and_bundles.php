<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gói thành viên (gói học viên, luôn 1 năm):
 * - membership_campaigns: chiến dịch tặng gói miễn phí cho học viên đăng ký trong một khoảng thời gian.
 * - membership_bundle_items: sản phẩm mua kèm được tặng gói (khoá học; tài liệu khi có module tài liệu).
 * - student_memberships: ghi nguồn (chiến dịch / sản phẩm) của mỗi gói.
 * - Bỏ cấu hình thời hạn gói (luôn 1 năm).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'starts_at', 'ends_at']);
        });

        Schema::create('membership_bundle_items', function (Blueprint $table) {
            $table->id();
            $table->string('item_type', 30);   // course | document (khi có)
            $table->unsignedBigInteger('item_id');
            $table->timestamp('created_at')->nullable();

            $table->unique(['item_type', 'item_id']);
        });

        Schema::table('student_memberships', function (Blueprint $table) {
            $table->foreignId('membership_campaign_id')->nullable()->after('source')
                ->constrained()->nullOnDelete();
            $table->string('source_item_type', 30)->nullable()->after('membership_campaign_id');
            $table->unsignedBigInteger('source_item_id')->nullable()->after('source_item_type');
        });

        DB::table('system_settings')->where('key', 'student_membership_months')->delete();
        DB::table('system_settings')->where('key', 'student_membership_price')->update([
            'label' => 'Giá gói thành viên (1 năm)',
            'description' => 'Giá bán gói thành viên 1 năm: xem tài liệu và tham gia các khoá huấn luyện. Sửa tại Marketing › Gói thành viên.',
            'updated_at' => now(),
        ]);

        Cache::forget('system_settings');
    }

    public function down(): void
    {
        Schema::table('student_memberships', function (Blueprint $table) {
            $table->dropConstrainedForeignId('membership_campaign_id');
            $table->dropColumn(['source_item_type', 'source_item_id']);
        });

        Schema::dropIfExists('membership_bundle_items');
        Schema::dropIfExists('membership_campaigns');

        DB::table('system_settings')->insert([
            'key' => 'student_membership_months',
            'value' => '12',
            'type' => 'integer',
            'group' => 'membership',
            'label' => 'Thời hạn gói học viên',
            'unit' => 'tháng',
            'description' => 'Số tháng gói học viên có hiệu lực kể từ ngày mua.',
            'sort_order' => 6,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Cache::forget('system_settings');
    }
};
