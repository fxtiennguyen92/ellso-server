<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Người hướng dẫn khoá huấn luyện = một tài khoản Giảng viên (thay cho tên nhập tay),
 * để giáo viên đăng nhập CMS xem khoá mình phụ trách (sau này: quản lý người đăng ký).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_courses', function (Blueprint $table) {
            $table->foreignId('trainer_id')->nullable()->after('price')->constrained('users')->nullOnDelete();
        });
        Schema::table('training_courses', function (Blueprint $table) {
            $table->dropColumn('trainer_name');
        });
    }

    public function down(): void
    {
        Schema::table('training_courses', function (Blueprint $table) {
            $table->string('trainer_name', 120)->nullable()->after('price');
        });
        Schema::table('training_courses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('trainer_id');
        });
    }
};
