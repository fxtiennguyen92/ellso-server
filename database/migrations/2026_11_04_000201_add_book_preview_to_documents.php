<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sách: bản đọc thử (vài trang / chương đầu), file riêng trên ổ tài liệu.
 * - preview_enabled: cho đọc thử (mặc định bật).
 * - preview_pages (PDF, mặc định 10) / preview_chapters (EPUB, mặc định 2): số trang / chương cắt ra.
 * - preview_path: file đọc thử; preview_status: pending | processing | ready | failed; preview_error.
 * - preview_count: số trang / chương thực tế của bản đọc thử; source_count: tổng số trang / chương của sách.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->boolean('preview_enabled')->default(true)->after('allow_download');
            $table->unsignedSmallInteger('preview_pages')->default(10)->after('preview_enabled');
            $table->unsignedSmallInteger('preview_chapters')->default(2)->after('preview_pages');
            $table->string('preview_path')->nullable()->after('preview_chapters');
            $table->string('preview_status', 20)->nullable()->after('preview_path');
            $table->text('preview_error')->nullable()->after('preview_status');
            $table->unsignedInteger('preview_count')->nullable()->after('preview_error');
            $table->unsignedInteger('source_count')->nullable()->after('preview_count');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn([
                'preview_enabled', 'preview_pages', 'preview_chapters', 'preview_path',
                'preview_status', 'preview_error', 'preview_count', 'source_count',
            ]);
        });
    }
};
