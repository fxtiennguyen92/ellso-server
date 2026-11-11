<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Theo dõi trải nghiệm học từng bài (app gửi lên):
 * - lesson_sessions: mỗi lần mở bài 1 phiên — thời gian học thực (chỉ tính khi app đang mở & có tương tác),
 *   độ sâu cuộn lớn nhất, đã cuộn tới cuối chưa, số giây xem video, hoàn thành bài trong phiên.
 * - lesson_feedback: đánh giá cuối bài (hữu ích / chưa hữu ích + lý do), mỗi học viên 1 đánh giá / bài (sửa được).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->string('platform', 20)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('last_seen_at');
            $table->unsignedInteger('active_seconds')->default(0);
            $table->unsignedTinyInteger('max_scroll_percent')->default(0);
            $table->boolean('reached_end')->default(false);
            $table->unsignedInteger('video_seconds')->default(0);
            $table->boolean('completed')->default(false);
            $table->timestamps();

            $table->index(['lesson_id', 'started_at']);
            $table->index(['user_id', 'lesson_id']);
        });

        Schema::create('lesson_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->tinyInteger('rating'); // 1 = hữu ích, -1 = chưa hữu ích
            $table->json('reasons')->nullable();
            $table->string('comment', 500)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'lesson_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_feedback');
        Schema::dropIfExists('lesson_sessions');
    }
};
