<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tác giả tài liệu: giảng viên trong hệ thống (author_id → users, để làm trang hồ sơ giảng viên + tài liệu của họ)
 * hoặc tác giả ngoài (chỉ tên, cột `author` có sẵn).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('author_id')->nullable()->after('author')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('author_id');
        });
    }
};
