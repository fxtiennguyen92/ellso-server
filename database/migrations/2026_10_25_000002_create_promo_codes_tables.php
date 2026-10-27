<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mã khuyến mãi (Marketing): người dùng nhập khi mua khoá học.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();             // 8–10 ký tự A-Z0-9, in hoa
            $table->string('name');                           // tên chương trình (nội bộ)
            $table->string('description', 500)->nullable();   // mô tả hiển thị cho học viên

            $table->string('discount_type', 20);              // percent | fixed
            $table->decimal('discount_value', 12, 2);         // % (VD 5.00) hoặc số tiền VND
            $table->unsignedBigInteger('max_discount')->nullable();      // trần số tiền giảm khi giảm theo %
            $table->unsignedBigInteger('min_order_amount')->nullable();  // giá trị đơn tối thiểu

            $table->timestamp('starts_at')->nullable();       // null = áp dụng ngay
            $table->timestamp('ends_at')->nullable();         // null = không hết hạn

            $table->unsignedInteger('usage_limit')->nullable();      // tổng số lượt, null = không giới hạn
            $table->unsignedInteger('per_user_limit')->nullable();   // mỗi người tối đa, null = không giới hạn
            $table->unsignedInteger('used_count')->default(0);

            $table->string('applies_to', 20)->default('all'); // all | selected (khoá học / danh mục bên dưới)
            $table->boolean('is_active')->default(true)->index();

            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('promo_code_course', function (Blueprint $table) {
            $table->foreignId('promo_code_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->primary(['promo_code_id', 'course_id']);
        });

        Schema::create('promo_code_category', function (Blueprint $table) {
            $table->foreignId('promo_code_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->primary(['promo_code_id', 'category_id']);
        });

        // Mỗi lần mã được dùng thành công trong một đơn hàng
        Schema::create('promo_code_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promo_code_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('order_id')->nullable()->index(); // nối khoá ngoại khi có bảng orders
            $table->unsignedBigInteger('order_amount');
            $table->unsignedBigInteger('discount_amount');
            $table->timestamp('created_at')->nullable();

            $table->index(['promo_code_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_code_redemptions');
        Schema::dropIfExists('promo_code_category');
        Schema::dropIfExists('promo_code_course');
        Schema::dropIfExists('promo_codes');
    }
};
