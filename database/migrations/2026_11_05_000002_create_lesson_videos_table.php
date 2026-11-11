<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Video tải riêng cho bài học (khối "Video" trong trình soạn nội dung), khác video tài liệu.
 * Xử lý giống video tài liệu: HLS 480p/720p mã hoá AES-128 trên ổ tài liệu, phát qua link có chữ ký.
 * Khối video trong nội dung bài chỉ lưu id video + chú thích + mốc thời gian.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->string('original_name')->nullable();
            $table->string('video_source_path')->nullable();
            $table->string('video_status', 20)->default('pending');
            $table->text('video_error')->nullable();
            $table->string('hls_path')->nullable();
            $table->text('hls_key')->nullable();
            $table->json('hls_variants')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_videos');
    }
};
