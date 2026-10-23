<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lộ trình = tổ hợp Nhóm học viên × Mục tiêu trong một khóa học
        Schema::create('learning_paths', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('learner_group_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('learning_goal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            // Mỗi tổ hợp nhóm × mục tiêu chỉ có 1 lộ trình trong một khóa
            $table->unique(['course_id', 'learner_group_id', 'learning_goal_id'], 'learning_paths_combo_unique');
        });

        // Chương riêng của từng lộ trình
        Schema::create('path_chapters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learning_path_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Bài học trong lộ trình = tham chiếu tới bài trong kho + thứ tự riêng
        Schema::create('path_lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learning_path_id')->constrained()->cascadeOnDelete();
            $table->foreignId('path_chapter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            // Một bài không lặp trong cùng chương. "Không lặp trong cả lộ trình" được kiểm tra
            // ở form CMS (để có thể kéo bài từ chương này sang chương khác trong một lần lưu).
            $table->unique(['path_chapter_id', 'lesson_id']);
            $table->index(['learning_path_id', 'lesson_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('path_lessons');
        Schema::dropIfExists('path_chapters');
        Schema::dropIfExists('learning_paths');
    }
};
