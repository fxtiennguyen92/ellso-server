<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tài liệu: Video (HLS 480p / 720p, mã hoá AES-128), PDF, Sách (PDF / EPUB).
 *
 * Quyền xem (đủ MỘT điều kiện là mở — theo layout app, canvas "Tài liệu — các trường hợp quyền xem"):
 *   miễn phí · gói thành viên · theo khoá học (đã được mở khoá) · theo bài học (bài đã mở) · mua riêng (chỉ Sách).
 * File nằm trên ổ riêng tư (config ellso.documents.disk: documents_local / s3), chỉ mở bằng link tạm có chữ ký.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_topics', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10)->index();                  // video | pdf | book
            $table->string('title');
            $table->string('author', 120)->nullable();
            $table->text('description')->nullable();
            $table->foreignId('document_topic_id')->nullable()->constrained()->nullOnDelete();
            $table->string('cover_color', 20)->default('teal');   // màu bìa (bảng màu chương)
            $table->string('cover_image')->nullable();            // ảnh bìa (disk public), tuỳ chọn

            // Quyền xem
            $table->boolean('is_free')->default(false);
            $table->boolean('requires_membership')->default(false);
            $table->unsignedBigInteger('price')->nullable();      // Sách bán riêng (VND); null = không bán
            $table->boolean('allow_download')->default(false);    // Sách: cho tải về

            // PDF / Sách
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('file_format', 10)->nullable();        // pdf | epub
            $table->unsignedBigInteger('file_size')->nullable();

            // Video
            $table->string('video_source_path')->nullable();      // file gốc chờ xử lý (xoá sau khi cắt HLS)
            $table->string('video_status', 20)->nullable();       // pending | processing | ready | failed
            $table->text('video_error')->nullable();
            $table->string('hls_path')->nullable();               // thư mục HLS trên ổ tài liệu
            $table->text('hls_key')->nullable();                  // khoá AES-128 (mã hoá bằng APP_KEY)
            $table->json('hls_variants')->nullable();             // [480, 720]
            $table->unsignedInteger('duration_seconds')->nullable();

            $table->string('status', 20)->default('draft')->index(); // draft | published
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // Điều kiện "theo khoá học" / "theo bài học"
        Schema::create('document_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained()->cascadeOnDelete();
            $table->index(['course_id']);
            $table->index(['lesson_id']);
        });

        // Sách đã mua (mua online sau này; hiện admin cấp trong Học bạ)
        Schema::create('document_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('price')->default(0);
            $table->string('source', 20);                         // admin | purchase
            $table->foreignId('granted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 300)->nullable();
            $table->timestamp('purchased_at');
            $table->timestamps();
            $table->unique(['user_id', 'document_id']);
        });

        // Tài liệu học viên lưu lại (Tài liệu của tôi)
        Schema::create('document_saves', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at');
            $table->primary(['user_id', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_saves');
        Schema::dropIfExists('document_purchases');
        Schema::dropIfExists('document_links');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('document_topics');
    }
};
