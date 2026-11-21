<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Khoá học ↔ Danh mục: từ 1 khoá 1 danh mục (courses.category_id) sang 1 khoá nhiều danh mục (bảng category_course).
 * Danh mục cũ của mỗi khoá được chuyển sang bảng mới rồi bỏ cột category_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_course', function (Blueprint $table) {
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->primary(['category_id', 'course_id']);
            $table->index('course_id');
        });

        DB::table('courses')->whereNotNull('category_id')->orderBy('id')->select(['id', 'category_id'])
            ->chunk(500, function ($rows) {
                DB::table('category_course')->insert($rows->map(fn ($r) => ['category_id' => $r->category_id, 'course_id' => $r->id])->all());
            });

        Schema::table('courses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        // Quay lại 1 danh mục: giữ danh mục có id nhỏ nhất của mỗi khoá
        DB::table('category_course')->selectRaw('course_id, MIN(category_id) as category_id')->groupBy('course_id')->get()
            ->each(fn ($r) => DB::table('courses')->where('id', $r->course_id)->update(['category_id' => $r->category_id]));

        Schema::dropIfExists('category_course');
    }
};
