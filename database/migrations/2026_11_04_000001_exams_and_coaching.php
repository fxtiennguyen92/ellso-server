<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bài thi + Coaching trong lộ trình.
 *
 * Bài thi:
 * - Ngân hàng đề dùng chung (exams → exam_questions → exam_options). Câu chọn 1 / chọn nhiều, có đáp án đúng.
 *   Mỗi lần làm rút ngẫu nhiên question_count câu (null = tất cả), đảo đáp án (shuffle_options),
 *   giới hạn thời gian tuỳ chọn (time_limit_minutes, null = không giới hạn). Đậu khi đúng ≥ 70%.
 * - Đặt trong lộ trình NGANG CẤP CHƯƠNG: path_chapters.kind = exam + exam_id (dùng chung thứ tự kéo thả với chương).
 * - exam_attempts: mỗi lần làm, lưu bộ câu đã giao (questions), câu trả lời, điểm /100, đậu / trượt.
 *
 * Coaching: lessons.kind = coaching (không vào thư viện bài học, không có nội dung, đặt lịch 1-1 làm sau).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('intro')->nullable();
            $table->unsignedSmallInteger('question_count')->nullable();
            $table->boolean('shuffle_options')->default(true);
            $table->unsignedSmallInteger('time_limit_minutes')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('exam_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);                 // single | multiple
            $table->text('prompt');
            $table->text('explanation')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('exam_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_question_id')->constrained()->cascadeOnDelete();
            $table->string('label', 500);
            $table->boolean('is_correct')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('path_chapters', function (Blueprint $table) {
            $table->string('kind', 20)->default('chapter')->after('learning_path_id');   // chapter | exam
            $table->foreignId('exam_id')->nullable()->after('kind')->constrained()->restrictOnDelete();
        });

        Schema::create('exam_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('path_chapter_id')->nullable()->constrained()->nullOnDelete(); // bài thi trong lộ trình nào
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->json('questions');                  // bộ câu đã giao: [{id, type, prompt, options:[{id,label}]}] (thứ tự hiển thị)
            $table->json('answers')->nullable();        // {question_id: [option_id…]}
            $table->json('review')->nullable();         // bản chụp chấm: [{question_id, prompt, correct, chosen_labels, correct_labels}]
            $table->unsignedSmallInteger('correct_count')->nullable();
            $table->unsignedSmallInteger('total')->nullable();
            $table->decimal('score', 5, 1)->nullable(); // /100
            $table->boolean('passed')->default(false);
            $table->timestamp('started_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->boolean('timed_out')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'path_chapter_id']);
            $table->index(['exam_id', 'submitted_at']);
        });

        Schema::table('lessons', function (Blueprint $table) {
            $table->string('kind', 20)->default('lesson')->after('id')->index(); // lesson | coaching
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropIndex(['kind']);
            $table->dropColumn('kind');
        });
        Schema::dropIfExists('exam_attempts');
        Schema::table('path_chapters', function (Blueprint $table) {
            $table->dropConstrainedForeignId('exam_id');
            $table->dropColumn('kind');
        });
        Schema::dropIfExists('exam_options');
        Schema::dropIfExists('exam_questions');
        Schema::dropIfExists('exams');
    }
};
