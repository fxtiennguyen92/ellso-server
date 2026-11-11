<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sự kiện ngoài lề: điểm tin về sự kiện của đối tác bên ngoài, chỉ để học viên biết thêm thông tin
 * (không đăng ký trong app). Ảnh 1280 × 720 (16:9), thời gian / địa điểm ghi dạng chữ,
 * hiển thị trên app trong khoảng starts_at – ends_at, nhiều sự kiện theo sort_order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_events', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('image_path');                     // 1280 × 720 trên disk media
            $table->string('time_text', 200);                 // VD: 19:00 thứ Bảy, 15/11/2026
            $table->string('location', 300);                  // địa chỉ hoặc "Online qua Zoom"
            $table->longText('description');                  // chữ thuần, xuống dòng = tách đoạn

            $table->timestamp('starts_at');                   // thời gian hiển thị trên app
            $table->timestamp('ends_at');
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);

            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_events');
    }
};
