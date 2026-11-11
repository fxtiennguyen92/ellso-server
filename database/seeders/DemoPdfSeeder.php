<?php

namespace Database\Seeders;

use App\Enums\ChapterColor;
use App\Enums\DocumentType;
use App\Models\Course;
use App\Models\Document;
use App\Models\DocumentTopic;
use App\Support\DocumentStorage;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * 4 file PDF MẪU cho tab Tài liệu (file thật trong database/seeders/files, tiếng Việt, xem được bằng trình đọc của app):
 * miễn phí · gói thành viên · bán lẻ · đi kèm khoá học (khoá demo đầu tiên).
 * Video mẫu không tạo được ở seeder (cần ffmpeg cắt HLS) → tải video thật trong CMS.
 * Chạy tay:  php artisan db:seed --class=DemoPdfSeeder   (chạy lại sẽ thay PDF mẫu cùng tên)
 */
class DemoPdfSeeder extends Seeder
{
    private const PDFS = [
        ['slug' => 'checklist-phan-bien', 'title' => 'Checklist 12 câu hỏi phản biện', 'author' => 'Phạm Quốc Bảo', 'topic' => 'Tư duy', 'access' => 'free'],
        ['slug' => 'mau-ke-hoach-1-1', 'title' => 'Mẫu kế hoạch họp 1-1 với nhân viên', 'author' => 'Nguyễn Thu Hà', 'topic' => 'Quản lý', 'access' => 'membership'],
        ['slug' => 'template-okr', 'title' => 'Bộ template OKR cho đội nhóm', 'author' => 'Phạm Quốc Bảo', 'topic' => 'Năng suất', 'access' => 'paid', 'price' => 79_000],
        ['slug' => 'prompt-mau-giao-dien', 'title' => 'Prompt mẫu để dựng giao diện', 'author' => 'Trần Hải Yến', 'topic' => 'Công nghệ & AI', 'access' => 'course'],
    ];

    public function run(): void
    {
        if (app()->isProduction() && ! config('ellso.allow_demo_seed')) {
            throw new RuntimeException('Không chạy dữ liệu demo trên production (đặt ALLOW_DEMO_SEED=true để cho phép tạm thời).');
        }

        Document::withTrashed()->whereIn('title', array_column(self::PDFS, 'title'))->get()->each->forceDelete();
        $course = Course::query()->where('status', 'published')->orderBy('id')->first();

        foreach (self::PDFS as $i => $p) {
            $topic = DocumentTopic::firstOrCreate(['name' => $p['topic']], ['sort_order' => 10 + $i]);
            $source = database_path("seeders/files/{$p['slug']}.pdf");
            $path = "pdfs/demo/{$p['slug']}.pdf";
            DocumentStorage::disk()->put($path, file_get_contents($source));

            $doc = Document::create([
                'type' => DocumentType::Pdf,
                'title' => $p['title'],
                'author' => $p['author'],
                'description' => 'Tài liệu mẫu của Ellso.',
                'document_topic_id' => $topic->id,
                'cover_color' => ChapterColor::Terracotta,
                'is_free' => $p['access'] === 'free',
                'requires_membership' => $p['access'] === 'membership',
                'price' => $p['price'] ?? null,
                'allow_download' => false,
                'file_path' => $path,
                'file_name' => "{$p['slug']}.pdf",
                'file_format' => 'pdf',
                'file_size' => filesize($source),
                'status' => 'published',
                'published_at' => now()->subDays(4 - $i)->subHours(2),
            ]);

            if ($p['access'] === 'course' && $course) {
                $doc->links()->create(['course_id' => $course->id]);
            }
        }
    }
}
