<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('instructor_id')->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('short_description', 500)->nullable();
            $table->longText('description')->nullable();
            $table->string('cover_image')->nullable();
            // Giá tính bằng VND (số nguyên). 0 = miễn phí
            $table->unsignedBigInteger('price')->default(0);
            // single = khóa học chung (1 lộ trình mặc định) | multi_path = đa lộ trình
            $table->string('type', 20)->default('single');
            // draft | pending | published | hidden
            $table->string('status', 20)->default('draft')->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Kho bài học của khóa (Lesson pool). Lộ trình chỉ tham chiếu tới đây.
        Schema::create('lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            // video | pdf | audio | article | infographic | quiz
            $table->string('type', 20)->index();
            $table->text('summary')->nullable();
            // Nội dung theo loại bài:
            $table->longText('body')->nullable();          // article: rich text
            $table->string('media_path')->nullable();      // video/pdf/audio: file đã upload
            $table->string('media_url')->nullable();       // video: link ngoài (YouTube, Vimeo, Bunny…)
            $table->json('images')->nullable();            // infographic: danh sách ảnh
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->boolean('is_preview')->default(false); // học thử, không cần mua
            $table->unsignedInteger('sort_order')->default(0); // thứ tự trong kho (chỉ để quản lý)
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lessons');
        Schema::dropIfExists('courses');
    }
};
