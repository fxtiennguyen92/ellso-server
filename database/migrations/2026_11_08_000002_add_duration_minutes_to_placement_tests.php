<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Thời gian làm bài ước tính (phút) — chỉ hiển thị trên app, không đếm ngược. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('placement_tests', function (Blueprint $table) {
            $table->unsignedSmallInteger('duration_minutes')->nullable()->after('question_count');
        });
    }

    public function down(): void
    {
        Schema::table('placement_tests', function (Blueprint $table) {
            $table->dropColumn('duration_minutes');
        });
    }
};
