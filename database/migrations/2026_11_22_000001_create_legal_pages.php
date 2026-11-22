<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Điều khoản sử dụng + Chính sách bảo mật: 2 trang cố định (slug terms | privacy), admin sửa nội dung (Markdown) trong CMS,
 * app đọc qua GET /v1/legal/{slug}. Nội dung mặc định lấy từ database/data/legal/*.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 32)->unique();
            $table->string('title');
            $table->longText('content');
            $table->string('version', 20)->default('1.0');
            $table->date('effective_on');
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();
        foreach (['terms' => 'Điều khoản sử dụng', 'privacy' => 'Chính sách bảo mật'] as $slug => $title) {
            DB::table('legal_pages')->insert([
                'slug' => $slug,
                'title' => $title,
                'content' => trim((string) file_get_contents(database_path("data/legal/{$slug}.md"))),
                'version' => '1.0',
                'effective_on' => $now->toDateString(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_pages');
    }
};
