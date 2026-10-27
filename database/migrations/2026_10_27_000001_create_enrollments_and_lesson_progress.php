<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ghi danh khoá học + tiến độ bài học.
 *
 * - course_enrollments: học viên được học khoá nào (nguồn: admin mở / mua / miễn phí / mã kích hoạt),
 *   lộ trình đang học (null = chưa chọn → app hỏi câu hỏi phân loại rồi gợi ý), hoạt động gần nhất, hoàn thành.
 * - lesson_progress: tiến độ theo HỌC VIÊN + BÀI (không theo lộ trình) — đổi lộ trình, bài trùng vẫn tính đã học.
 *   Hoàn thành = đã đọc đến cuối + trả lời đúng mọi câu hỏi trắc nghiệm trong bài (server chấm).
 * - lesson_quiz_answers: mỗi lần trả lời một câu hỏi (để xem trong Học bạ, thống kê câu khó).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('learning_path_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 20);                 // admin | purchase | free | code
            $table->foreignId('granted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 300)->nullable();
            $table->timestamp('enrolled_at');
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('completed_at')->nullable(); // hoàn thành mọi bài của lộ trình
            $table->timestamps();

            $table->unique(['user_id', 'course_id']);
        });

        Schema::create('lesson_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->timestamp('first_viewed_at')->nullable();
            $table->timestamp('last_viewed_at')->nullable();
            $table->timestamp('read_at')->nullable();      // đọc đến cuối bài
            $table->timestamp('completed_at')->nullable(); // đọc xong + đúng hết câu hỏi
            $table->timestamps();

            $table->unique(['user_id', 'lesson_id']);
            $table->index(['user_id', 'completed_at']);
        });

        Schema::create('lesson_quiz_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->string('block_id', 32);               // id khối câu hỏi trong nội dung bài
            $table->json('option_ids');
            $table->boolean('is_correct');
            $table->timestamp('created_at');

            $table->index(['user_id', 'lesson_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_quiz_answers');
        Schema::dropIfExists('lesson_progress');
        Schema::dropIfExists('course_enrollments');
    }
};
