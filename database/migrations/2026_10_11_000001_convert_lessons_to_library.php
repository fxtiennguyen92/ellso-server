<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bài học → thư viện dùng chung, nội dung Editor.js.
 *
 * - lessons: bỏ course_id / type / các cột media; thêm owner_id, icon,
 *   content_draft + content_published (JSON Editor.js), content_updated_at, published_at.
 * - path_chapters: thêm color (mã trong App\Enums\ChapterColor).
 * - path_lessons: thêm unlock_mode, is_preview (chuyển từ lessons).
 *
 * Nội dung cũ (cột body, HTML) được chuyển thành 1 khối "paragraph" trong bản nháp.
 */
return new class extends Migration
{
    private const COLORS = ['sage', 'teal', 'steel', 'indigo', 'plum', 'rose', 'terracotta', 'ochre', 'graphite'];

    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->foreignId('owner_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
            $table->string('icon')->nullable()->after('title');
            $table->json('content_draft')->nullable()->after('summary');
            $table->json('content_published')->nullable()->after('content_draft');
            // Bản nháp có ít nhất 1 khối (tính sẵn để lọc nhanh)
            $table->boolean('has_draft_content')->default(false)->after('content_published');
            $table->timestamp('content_updated_at')->nullable()->after('content_published');
            $table->timestamp('published_at')->nullable()->after('content_updated_at');
        });

        Schema::table('path_chapters', function (Blueprint $table) {
            $table->string('color', 20)->default('sage')->after('title');
        });

        Schema::table('path_lessons', function (Blueprint $table) {
            // after_previous | always (App\Enums\UnlockMode)
            $table->string('unlock_mode', 20)->default('after_previous')->after('lesson_id');
            $table->boolean('is_preview')->default(false)->after('unlock_mode');
        });

        $this->migrateData();

        Schema::table('lessons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('course_id');
            $table->dropIndex(['type']);
            $table->dropColumn(['type', 'body', 'media_path', 'media_url', 'images', 'duration_seconds', 'is_preview', 'sort_order']);
        });
    }

    private function migrateData(): void
    {
        // Chủ sở hữu = giảng viên của khóa cũ; nội dung HTML cũ → 1 khối paragraph
        foreach (DB::table('lessons')->get() as $lesson) {
            $ownerId = DB::table('courses')->where('id', $lesson->course_id)->value('instructor_id');
            $draft = filled($lesson->body)
                ? json_encode([
                    'time' => now()->getTimestampMs(),
                    'blocks' => [['id' => 'migrated', 'type' => 'paragraph', 'data' => ['text' => $lesson->body]]],
                    'version' => '2.31.0',
                ])
                : null;

            DB::table('lessons')->where('id', $lesson->id)->update([
                'owner_id' => $ownerId,
                'content_draft' => $draft,
                'has_draft_content' => $draft !== null,
                'content_updated_at' => $draft ? now() : null,
            ]);

            DB::table('path_lessons')->where('lesson_id', $lesson->id)->update(['is_preview' => (bool) $lesson->is_preview]);
        }

        // Màu chương: lần lượt theo bảng màu trong từng lộ trình
        foreach (DB::table('path_chapters')->orderBy('learning_path_id')->orderBy('sort_order')->get()->groupBy('learning_path_id') as $chapters) {
            foreach ($chapters->values() as $i => $chapter) {
                DB::table('path_chapters')->where('id', $chapter->id)->update(['color' => self::COLORS[$i % count(self::COLORS)]]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->foreignId('course_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 20)->default('article')->index();
            $table->longText('body')->nullable();
            $table->string('media_path')->nullable();
            $table->string('media_url')->nullable();
            $table->json('images')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->boolean('is_preview')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
        });

        Schema::table('lessons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_id');
            $table->dropColumn(['icon', 'content_draft', 'content_published', 'has_draft_content', 'content_updated_at', 'published_at']);
        });

        Schema::table('path_chapters', fn (Blueprint $table) => $table->dropColumn('color'));
        Schema::table('path_lessons', fn (Blueprint $table) => $table->dropColumn(['unlock_mode', 'is_preview']));
    }
};
