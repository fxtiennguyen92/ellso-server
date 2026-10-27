<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bài kiểm tra đầu vào (học viên làm sau khi đăng ký).
 *
 * Chấm điểm tự động theo "phân loại đánh giá" (VD: Khả năng tiếp thu):
 *  - Mỗi đáp án cộng (hoặc trừ) điểm cho một hay nhiều phân loại: placement_options.scores = {dimension_id: điểm}.
 *  - Câu sắp xếp: điểm của mục × trọng số vị trí mà học viên xếp nó (placement_questions.position_weights).
 *  - Tổng điểm của phân loại ≥ min_score của mức nào (cao nhất) → đạt mức đó (VD: Xuất sắc).
 * Mỗi mức có thể gợi ý khoá học. Bài làm lưu kèm bản chụp câu trả lời + kết quả để không đổi khi sửa đề.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('placement_tests', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('intro')->nullable();                 // lời giới thiệu trước khi làm bài
            $table->boolean('is_active')->default(false)->index(); // chỉ 1 bài đang dùng
            $table->timestamps();
        });

        // Phân loại đánh giá
        Schema::create('placement_dimensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('placement_test_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Mức độ của phân loại: đạt khi tổng điểm ≥ min_score
        Schema::create('placement_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('placement_dimension_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->integer('min_score')->default(0);
            $table->text('description')->nullable();           // nhận xét hiện cho học viên
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Khoá học gợi ý cho từng mức
        Schema::create('placement_level_courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('placement_level_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->unique(['placement_level_id', 'course_id']);
        });

        Schema::create('placement_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('placement_test_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);                         // single | multiple | ordering
            $table->text('prompt');
            $table->string('hint', 300)->nullable();
            $table->unsignedTinyInteger('min_choices')->nullable(); // chọn nhiều
            $table->unsignedTinyInteger('max_choices')->nullable();
            $table->json('position_weights')->nullable();      // sắp xếp: [hạng 1, hạng 2, …]
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('placement_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('placement_question_id')->constrained()->cascadeOnDelete();
            $table->string('label', 500);
            $table->json('scores')->nullable();                // {dimension_id: điểm}
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('placement_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('placement_test_id')->constrained()->cascadeOnDelete();
            $table->json('answers');                           // [{question_id, prompt, type, option_ids, labels}]
            $table->json('results');                           // [{dimension_id, name, score, min, max, level_id, level, …}]
            $table->timestamp('completed_at');
            $table->timestamps();

            $table->index(['user_id', 'completed_at']);
            $table->index(['placement_test_id', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('placement_attempts');
        Schema::dropIfExists('placement_options');
        Schema::dropIfExists('placement_questions');
        Schema::dropIfExists('placement_level_courses');
        Schema::dropIfExists('placement_levels');
        Schema::dropIfExists('placement_dimensions');
        Schema::dropIfExists('placement_tests');
    }
};
