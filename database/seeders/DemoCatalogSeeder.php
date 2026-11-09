<?php

namespace Database\Seeders;

use App\Enums\CourseStatus;
use App\Enums\CourseType;
use App\Enums\UnlockMode;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use App\Support\MediaStorage;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Thêm khoá học mẫu (chưa có bài) cho mục Khám phá khoá học trên app — đủ nhiều để thử tải thêm khi cuộn.
 * php artisan db:seed --class=DemoCatalogSeeder
 */
class DemoCatalogSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction() && ! config('ellso.allow_demo_seed')) {
            throw new RuntimeException('Không chạy dữ liệu demo trên production (đặt ALLOW_DEMO_SEED=true để cho phép tạm thời).');
        }

        $teachers = collect([
            ['giangvien@ellso.test', 'Giảng viên Demo'],
            ['haiyen@ellso.test', 'Trần Hải Yến'],
            ['quocbao@ellso.test', 'Phạm Quốc Bảo'],
            ['thuha@ellso.test', 'Nguyễn Thu Hà'],
        ])->map(fn ($t) => User::firstOrCreate(['email' => $t[0]], [
            'name' => $t[1], 'password' => 'password', 'role' => UserRole::Instructor, 'is_active' => true,
            'email_verified_at' => now(), 'password_changed_at' => now(),
        ]));

        $cats = collect(['Kỹ năng mềm', 'Lãnh đạo', 'Năng suất', 'Công nghệ & AI'])
            ->mapWithKeys(fn ($name, $i) => [$name => Category::firstOrCreate(['name' => $name], ['sort_order' => $i + 1, 'is_active' => true])]);

        $courses = [
            ['Giao tiếp thuyết phục', 'Kỹ năng mềm', 690000, 'Nói để người khác muốn nghe và làm theo.'],
            ['Quản lý thời gian hiệu quả', 'Năng suất', 0, 'Lập kế hoạch tuần và giữ nhịp làm việc.'],
            ['Phân tích dữ liệu cho người mới', 'Công nghệ & AI', 1290000, 'Đọc số liệu, làm báo cáo, ra quyết định.'],
            ['Dẫn dắt đội nhóm từ xa', 'Lãnh đạo', 890000, 'Giữ đội nhóm gắn kết khi làm việc từ xa.'],
            ['Thuyết trình tự tin', 'Kỹ năng mềm', 590000, 'Chuẩn bị, luyện tập và làm chủ sân khấu.'],
            ['Viết email chuyên nghiệp', 'Kỹ năng mềm', 290000, 'Rõ ràng, lịch sự, đúng trọng tâm.'],
            ['Huấn luyện nhân viên 1-1', 'Lãnh đạo', 990000, 'Đặt câu hỏi, lắng nghe và phản hồi.'],
            ['Ra quyết định dưới áp lực', 'Lãnh đạo', 790000, 'Khung tư duy để chọn nhanh mà chắc.'],
            ['Tập trung sâu', 'Năng suất', 390000, 'Làm việc không bị xao nhãng.'],
            ['Ghi chú thông minh', 'Năng suất', 0, 'Hệ thống ghi chú giúp nhớ lâu, dùng lại được.'],
            ['Prompt cho công việc hằng ngày', 'Công nghệ & AI', 490000, 'Dùng AI để viết, tóm tắt, phân tích.'],
            ['Tự động hoá với AI', 'Công nghệ & AI', 1490000, 'Nối các công cụ để bớt việc lặp lại.'],
        ];

        foreach ($courses as $i => [$title, $cat, $price, $short]) {
            $course = Course::firstOrCreate(['title' => $title], [
                'short_description' => $short,
                'category_id' => $cats[$cat]->id,
                'instructor_id' => $teachers[$i % $teachers->count()]->id,
                'type' => CourseType::Single,
                'status' => CourseStatus::Published,
                'price' => $price,
            ]);
            if (! $course->description) {
                $course->forceFill(['description' => self::description($title, $short)])->saveQuietly();
            }
            // Rải ngày xuất bản: vài khoá mới (nhãn "Mới"), còn lại cũ hơn
            $course->forceFill(['published_at' => now()->subDays($i < 3 ? $i * 5 + 2 : 40 + $i * 9)])->saveQuietly();
            $this->seedOutline($course, $title);
        }

        // Thêm 1 bài coaching (cuối chương 1) + Thi giữa kỳ + Thi cuối kỳ vào mọi lộ trình (dọn bản cũ trước nên chạy lại được)
        $this->call(DemoExamSeeder::class);
    }

    /** Mô tả mẫu (HTML như RichEditor) để trang giới thiệu khoá trên app có nội dung. */
    private static function description(string $title, string $short): string
    {
        return '<p>'.e($short).' Khoá <strong>'.e($title).'</strong> gồm các bài ngắn, học trên điện thoại, mỗi bài 5–10 phút.</p>'
            .'<h2>Bạn sẽ học được gì</h2>'
            .'<ul><li>Nắm khung tư duy cốt lõi và áp dụng ngay vào công việc</li><li>Luyện tập qua tình huống thực tế</li><li>Tự đánh giá tiến bộ sau mỗi chương</li></ul>'
            .'<h2>Khoá học dành cho ai</h2>'
            .'<p>Người đi làm muốn nâng cấp kỹ năng một cách bài bản, không cần kiến thức nền.</p>';
    }

    /** Chương + bài mẫu (đã xuất bản, nội dung vài dòng) cho lộ trình mặc định của khoá — bỏ qua nếu đã có chương. */
    private function seedOutline(Course $course, string $title): void
    {
        $course->ensureDefaultPath();
        $path = $course->defaultPath()->first();
        if (! $path || $path->chapters()->exists()) {
            return;
        }

        $topic = mb_strtolower($title);
        $outline = [
            ['Nền tảng', 'sage', ["Vì sao cần {$topic}?", 'Những khái niệm cốt lõi', 'Sai lầm thường gặp']],
            ['Áp dụng vào công việc', 'teal', ['Khung 4 bước dễ nhớ', 'Tình huống thực tế', 'Luyện tập có hướng dẫn', 'Bài tập: tự đánh giá']],
            ['Nâng cao', 'plum', ['Mẹo từ người hướng dẫn', 'Xây thói quen bền vững', 'Tổng kết & kế hoạch hành động']],
        ];

        foreach ($outline as $ci => [$chapterTitle, $color, $lessons]) {
            $chapter = $path->chapters()->create(['title' => $chapterTitle, 'color' => $color, 'sort_order' => $ci + 1]);
            foreach ($lessons as $li => $lessonTitle) {
                $lesson = Lesson::create([
                    'owner_id' => $course->instructor_id,
                    'title' => $lessonTitle,
                    'content_draft' => [
                        'time' => now()->getTimestampMs(),
                        'blocks' => self::sampleBlocks($title, $lessonTitle, $ci * 10 + $li),
                        'version' => '2.31.0',
                    ],
                ]);
                $lesson->publish();
                $chapter->pathLessons()->create([
                    'learning_path_id' => $path->id,
                    'lesson_id' => $lesson->id,
                    'unlock_mode' => UnlockMode::AfterPrevious,
                    'is_preview' => $ci === 0 && $li === 0,
                    'sort_order' => $li + 1,
                ]);
            }
        }
    }

    /**
     * Nội dung mẫu có đủ các loại khối app cần hiển thị (trừ video — cần tải video thật trong CMS để cắt HLS):
     * tiêu đề, đoạn văn có định dạng, danh sách (chấm / số / kiểm, lồng nhau), trích dẫn, lưu ý, ảnh, bảng, code,
     * đường phân cách, câu hỏi chọn 1 và chọn nhiều.
     */
    private static function sampleBlocks(string $course, string $lesson, int $n): array
    {
        $image = self::lessonImage();

        return [
            ['id' => 'h1', 'type' => 'header', 'data' => ['text' => 'Mở đầu', 'level' => 2]],
            ['id' => 'p1', 'type' => 'paragraph', 'data' => ['text' => "Trong bài <b>{$lesson}</b> của khoá <i>{$course}</i>, bạn sẽ đi từ khái niệm đến cách áp dụng. Những ý quan trọng được <mark class=\"cdx-marker\">tô sáng</mark>, thuật ngữ viết dạng <code class=\"inline-code\">code</code>. Đọc thêm tại <a href=\"https://ellso.vn\">ellso.vn</a>."]],
            ['id' => 'q0', 'type' => 'quote', 'data' => ['text' => 'Học không phải để biết nhiều hơn, mà để làm tốt hơn.', 'caption' => 'Ellso', 'alignment' => 'left']],
            ['id' => 'h2', 'type' => 'header', 'data' => ['text' => 'Ý chính cần nhớ', 'level' => 3]],
            ['id' => 'l1', 'type' => 'list', 'data' => ['style' => 'unordered', 'meta' => [], 'items' => [
                ['content' => 'Xác định <b>mục tiêu</b> trước khi bắt tay vào làm', 'meta' => [], 'items' => []],
                ['content' => 'Chia nhỏ công việc thành các bước', 'meta' => [], 'items' => [
                    ['content' => 'Mỗi bước có kết quả rõ ràng', 'meta' => [], 'items' => []],
                    ['content' => 'Ước lượng thời gian cho từng bước', 'meta' => [], 'items' => []],
                ]],
                ['content' => 'Xem lại kết quả và rút kinh nghiệm', 'meta' => [], 'items' => []],
            ]]],
            ['id' => 'w1', 'type' => 'warning', 'data' => ['title' => 'Lưu ý', 'message' => 'Đừng cố làm hết trong một lần — <b>đều đặn</b> quan trọng hơn cường độ.']],
            ['id' => 'img', 'type' => 'image', 'data' => ['file' => ['url' => $image], 'caption' => 'Sơ đồ minh hoạ các bước', 'withBorder' => false, 'stretched' => false, 'withBackground' => $n % 2 === 1]],
            ['id' => 'h3', 'type' => 'header', 'data' => ['text' => 'Quy trình 4 bước', 'level' => 3]],
            ['id' => 'l2', 'type' => 'list', 'data' => ['style' => 'ordered', 'meta' => ['start' => 1, 'counterType' => 'numeric'], 'items' => [
                ['content' => 'Quan sát tình huống', 'meta' => [], 'items' => []],
                ['content' => 'Đặt câu hỏi đúng', 'meta' => [], 'items' => []],
                ['content' => 'Thử một giải pháp nhỏ', 'meta' => [], 'items' => []],
                ['content' => 'Đánh giá và điều chỉnh', 'meta' => [], 'items' => []],
            ]]],
            ['id' => 't1', 'type' => 'table', 'data' => ['withHeadings' => true, 'stretched' => false, 'content' => [
                ['Bước', 'Việc cần làm', 'Thời gian'],
                ['1', 'Quan sát & ghi chú', '10 phút'],
                ['2', 'Viết ra 3 câu hỏi', '5 phút'],
                ['3', 'Thử nghiệm', '1 ngày'],
            ]]],
            ['id' => 'quiz1', 'type' => 'quiz', 'data' => [
                'type' => 'single',
                'question' => 'Bước đầu tiên của quy trình là gì?',
                'options' => [
                    ['id' => 'a', 'text' => 'Thử một giải pháp nhỏ', 'correct' => false],
                    ['id' => 'b', 'text' => 'Quan sát tình huống', 'correct' => true],
                    ['id' => 'c', 'text' => 'Đánh giá và điều chỉnh', 'correct' => false],
                ],
                'explanation' => 'Quan sát trước giúp bạn hiểu đúng vấn đề rồi mới hành động.',
            ]],
            ['id' => 'd1', 'type' => 'delimiter', 'data' => []],
            ['id' => 'h4', 'type' => 'header', 'data' => ['text' => 'Ví dụ ghi chú', 'level' => 3]],
            ['id' => 'c1', 'type' => 'code', 'data' => ['code' => "Mục tiêu: hoàn thành báo cáo tuần\nBước 1: gom số liệu (thứ 2)\nBước 2: viết nháp (thứ 3)\nBước 3: gửi góp ý (thứ 4)"]],
            ['id' => 'l3', 'type' => 'list', 'data' => ['style' => 'checklist', 'meta' => [], 'items' => [
                ['content' => 'Đã đọc hết bài', 'meta' => ['checked' => true], 'items' => []],
                ['content' => 'Viết ra mục tiêu của tuần này', 'meta' => ['checked' => false], 'items' => []],
                ['content' => 'Thử áp dụng một bước', 'meta' => ['checked' => false], 'items' => []],
            ]]],
            ['id' => 'quiz2', 'type' => 'quiz', 'data' => [
                'type' => 'multiple',
                'question' => 'Những điều nào giúp bạn duy trì việc học? (chọn nhiều)',
                'options' => [
                    ['id' => 'a', 'text' => 'Học đều đặn mỗi ngày một ít', 'correct' => true],
                    ['id' => 'b', 'text' => 'Dồn hết vào cuối tuần', 'correct' => false],
                    ['id' => 'c', 'text' => 'Ghi lại điều đã học', 'correct' => true],
                ],
                'explanation' => 'Đều đặn và ghi chép giúp nhớ lâu hơn học dồn.',
            ]],
            ['id' => 'p2', 'type' => 'paragraph', 'data' => ['text' => 'Hết bài rồi! Hãy thử áp dụng ngay hôm nay nhé.']],
        ];
    }

    private static ?string $imageUrl = null;

    /** Ảnh minh hoạ dùng chung cho các bài mẫu (vẽ bằng GD, lưu vào ổ ảnh). */
    private static function lessonImage(): string
    {
        if (self::$imageUrl) {
            return self::$imageUrl;
        }
        $path = 'lessons/content/demo/steps.png';
        $img = imagecreatetruecolor(1200, 640);
        imagefill($img, 0, 0, imagecolorallocate($img, 236, 253, 245));
        $colors = [[16, 185, 129], [20, 184, 166], [245, 158, 11], [139, 92, 246]];
        foreach ($colors as $i => $c) {
            $x = 170 + $i * 290;
            if ($i < 3) {
                imagefilledrectangle($img, $x, 312, $x + 290, 328, imagecolorallocate($img, 167, 243, 208));
            }
            imagefilledellipse($img, $x, 320, 190, 190, imagecolorallocate($img, ...$c));
            imagefilledellipse($img, $x, 320, 90, 90, imagecolorallocate($img, 255, 255, 255));
        }
        ob_start();
        imagepng($img);
        MediaStorage::disk()->put($path, ob_get_clean());

        return self::$imageUrl = MediaStorage::url($path);
    }
}
