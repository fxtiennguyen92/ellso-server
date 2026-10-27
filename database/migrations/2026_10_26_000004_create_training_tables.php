<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Khoá huấn luyện (offline). Một khoá = một buổi học, tổ chức vào nhiều ngày (lịch).
 * Mỗi ngày có địa điểm (chọn từ sổ địa chỉ, có nhãn để dùng lại) và số người tối đa riêng.
 * Giá tính theo khoá (mỗi người), không theo ngày.
 *
 * VD: Khoá Quản lý 200.000 ₫/người — 15/11 ở Cafe A (tối đa 10), 20/11 ở Cafe B (tối đa 20).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Sổ địa chỉ
        Schema::create('training_locations', function (Blueprint $table) {
            $table->id();
            $table->string('label', 80);                  // tên gợi nhớ, VD: Cafe A – Quận 1
            $table->string('address', 300);
            $table->string('map_url', 500)->nullable();   // link Google Maps
            $table->string('note', 300)->nullable();      // VD: tầng 2, gửi xe phía sau
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('training_courses', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('summary', 300)->nullable();
            $table->string('banner_path');                // 1280 × 720 (16:9)
            $table->unsignedBigInteger('price')->default(0); // VND / người
            $table->string('trainer_name', 120)->nullable();
            $table->json('content')->nullable();          // khối nội dung (như Chiến dịch, không có mã khuyến mãi)
            $table->string('status', 20)->default('draft')->index(); // draft | published | hidden
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // Lịch tổ chức: mỗi ngày một dòng
        Schema::create('training_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_location_id')->constrained()->restrictOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unsignedInteger('capacity');           // số người tối đa
            $table->string('note', 300)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['training_course_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_sessions');
        Schema::dropIfExists('training_courses');
        Schema::dropIfExists('training_locations');
    }
};
