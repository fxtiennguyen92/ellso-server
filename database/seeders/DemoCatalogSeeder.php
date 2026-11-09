<?php

namespace Database\Seeders;

use App\Enums\CourseStatus;
use App\Enums\CourseType;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Course;
use App\Models\User;
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
        }
    }

    /** Mô tả mẫu (HTML như RichEditor) để trang giới thiệu khoá trên app có nội dung. */
    private static function description(string $title, string $short): string
    {
        return '<p>' . e($short) . ' Khoá <strong>' . e($title) . '</strong> gồm các bài ngắn, học trên điện thoại, mỗi bài 5–10 phút.</p>'
            . '<h2>Bạn sẽ học được gì</h2>'
            . '<ul><li>Nắm khung tư duy cốt lõi và áp dụng ngay vào công việc</li><li>Luyện tập qua tình huống thực tế</li><li>Tự đánh giá tiến bộ sau mỗi chương</li></ul>'
            . '<h2>Khoá học dành cho ai</h2>'
            . '<p>Người đi làm muốn nâng cấp kỹ năng một cách bài bản, không cần kiến thức nền.</p>';
    }
}
