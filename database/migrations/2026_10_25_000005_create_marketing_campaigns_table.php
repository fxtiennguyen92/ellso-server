<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chiến dịch marketing: banner trên Home của app (1200 × 500, tỉ lệ 12:5) + trang chi tiết chiến dịch.
 * Nhiều chiến dịch hiển thị cùng lúc → app hiện dạng trượt, theo sort_order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('title');                          // tiêu đề trang chi tiết, cũng là mô tả ảnh banner
            $table->string('summary', 300)->nullable();        // 1–2 câu dưới tiêu đề
            $table->string('banner_path');                    // ảnh 1200 × 500 trên disk public

            $table->timestamp('starts_at');                   // thời gian hiển thị
            $table->timestamp('ends_at');
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0); // thứ tự trong dải banner

            $table->json('content')->nullable();              // khối nội dung (đoạn văn, các bước, điều kiện, mã KM…)

            // Nút hành động cuối trang chi tiết
            $table->string('cta_type', 20)->default('none');  // none | url | course
            $table->string('cta_label', 60)->nullable();
            $table->string('cta_url', 500)->nullable();
            $table->foreignId('cta_course_id')->nullable()->constrained('courses')->nullOnDelete();

            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_campaigns');
    }
};
