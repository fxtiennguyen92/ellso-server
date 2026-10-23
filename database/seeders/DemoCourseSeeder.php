<?php

namespace Database\Seeders;

use App\Enums\CourseStatus;
use App\Enums\CourseType;
use App\Enums\UnlockMode;
use App\Models\Lesson;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Classification;
use App\Models\ClassificationOption;
use App\Models\Course;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Dữ liệu mẫu cho môi trường local:
 * - "Tạo Web bằng AI": phân loại theo Nghề nghiệp + Mục tiêu học (dùng chung)
 * - "Tư duy phản biện": phân loại theo Mục tiêu học (dùng chung) + Điểm yếu trong tư duy (riêng của khóa)
 */
class DemoCourseSeeder extends Seeder
{
    private User $instructor;

    private Category $category;

    public function run(): void
    {
        $this->instructor = User::updateOrCreate(
            ['email' => 'giangvien@ellso.test'],
            [
                'name' => 'Giảng viên Demo',
                'password' => 'password',
                'role' => UserRole::Instructor,
                'is_active' => true,
                'email_verified_at' => now(),
                'password_changed_at' => now(),
            ],
        );

        User::updateOrCreate(
            ['email' => 'hocvien@ellso.test'],
            ['name' => 'Học viên Demo', 'password' => 'password', 'role' => UserRole::Student, 'is_active' => true, 'password_changed_at' => now()],
        );

        $this->category = Category::firstOrCreate(['name' => 'Kỹ năng & Công nghệ']);

        $this->seedWebAiCourse();
        $this->seedCriticalThinkingCourse();
    }

    private function seedWebAiCourse(): void
    {
        if (Course::where('title', 'Tạo Web bằng AI')->exists()) {
            return;
        }

        $course = $this->makeCourse('Tạo Web bằng AI', 'Dùng công cụ AI để lên ý tưởng, tạo code và triển khai website.', 499000);

        $lessons = $this->makeLessons($course, [
            'intro' => ['Tổng quan: AI tạo web là gì?', true],
            'concepts' => ['Khái niệm cơ bản: frontend, backend, hosting', false],
            'demo' => ['Demo: tạo landing page trong 10 phút', false],
            'case' => ['Case study: chiến dịch marketing dùng web AI', false],
            'quiz_basic' => ['Quiz: kiến thức tổng quan', false],
            'step' => ['Hướng dẫn từng bước: từ prompt đến website', false],
            'deploy' => ['Triển khai web đơn giản', false],
            'arch' => ['Kiến trúc ứng dụng web hiện đại', false],
            'prompt' => ['Prompt cho code: kỹ thuật nâng cao', false],
            'api' => ['Tích hợp API', false],
            'optimize' => ['Tối ưu hiệu năng & bảo mật', false],
            'quiz_adv' => ['Quiz: kỹ thuật chuyên sâu', false],
        ]);

        $job = Classification::shared()->where('name', 'Nghề nghiệp')->firstOrFail();
        $goal = Classification::shared()->where('name', 'Mục tiêu học')->firstOrFail();
        $course->classifications()->attach([$job->id => ['sort_order' => 1], $goal->id => ['sort_order' => 2]]);

        $this->makePaths($course, $lessons, [
            [
                'name' => 'A. Marketing — Hiểu', 'default' => true,
                'options' => [[$job, ['Marketing']], [$goal, ['Hiểu']]],
                'chapters' => ['Làm quen' => ['intro', 'concepts'], 'Ứng dụng thực tế' => ['demo', 'case', 'quiz_basic']],
            ],
            [
                'name' => 'B. Marketing — Làm được',
                'options' => [[$job, ['Marketing']], [$goal, ['Làm được']]],
                'chapters' => ['Nền tảng' => ['intro', 'concepts', 'demo'], 'Thực hành' => ['step', 'deploy', 'quiz_basic']],
            ],
            [
                // 1 lộ trình cho nhiều giá trị: IT với mục tiêu Làm được hoặc Chuyên sâu
                'name' => 'C. IT — Code',
                'options' => [[$job, ['IT']], [$goal, ['Làm được', 'Chuyên sâu']]],
                'chapters' => ['Kiến trúc' => ['arch', 'prompt'], 'Tích hợp & tối ưu' => ['api', 'optimize', 'quiz_adv']],
            ],
        ]);
    }

    private function seedCriticalThinkingCourse(): void
    {
        if (Course::where('title', 'Tư duy phản biện')->exists()) {
            return;
        }

        $course = $this->makeCourse('Tư duy phản biện', 'Nhận diện và khắc phục các lỗi tư duy thường gặp.', 299000);

        $lessons = $this->makeLessons($course, [
            'intro' => ['Tư duy phản biện là gì?', true],
            'bias' => ['Thiên kiến xác nhận', false],
            'jump' => ['Vội vàng kết luận', false],
            'emotion' => ['Cảm xúc chi phối lập luận', false],
            'tools' => ['Bộ công cụ đặt câu hỏi', false],
            'practice' => ['Bài tập tình huống', false],
            'quiz' => ['Quiz: nhận diện lỗi tư duy', false],
        ]);

        $goal = Classification::shared()->where('name', 'Mục tiêu học')->firstOrFail();
        $course->classifications()->attach([$goal->id => ['sort_order' => 1]]);

        // Phân loại riêng của khóa — tự gắn vào khóa với thứ tự kế tiếp
        $weakness = Classification::create([
            'course_id' => $course->id,
            'name' => 'Điểm yếu trong tư duy',
            'question' => 'Bạn thấy mình hay mắc lỗi nào nhất?',
        ]);
        foreach (['Thiên kiến xác nhận', 'Vội kết luận', 'Cảm xúc chi phối'] as $i => $name) {
            $weakness->options()->create(['name' => $name, 'sort_order' => $i]);
        }

        $this->makePaths($course, $lessons, [
            [
                'name' => 'Tổng quan (mọi học viên)', 'default' => true,
                'options' => [],
                'chapters' => ['Nhập môn' => ['intro', 'tools'], 'Lỗi thường gặp' => ['bias', 'jump', 'emotion', 'quiz']],
            ],
            [
                'name' => 'Khắc phục thiên kiến xác nhận',
                'options' => [[$weakness, ['Thiên kiến xác nhận']]],
                'chapters' => ['Nhập môn' => ['intro'], 'Đi sâu' => ['bias', 'tools', 'practice', 'quiz']],
            ],
            [
                'name' => 'Bình tĩnh trước khi kết luận',
                'options' => [[$weakness, ['Vội kết luận', 'Cảm xúc chi phối']], [$goal, ['Làm được', 'Chuyên sâu']]],
                'chapters' => ['Nhập môn' => ['intro'], 'Rèn luyện' => ['jump', 'emotion', 'practice', 'quiz']],
            ],
        ]);
    }

    private function makeCourse(string $title, string $short, int $price): Course
    {
        return Course::create([
            'title' => $title,
            'short_description' => $short,
            'category_id' => $this->category->id,
            'instructor_id' => $this->instructor->id,
            'type' => CourseType::MultiPath,
            'status' => CourseStatus::Published,
            'price' => $price,
        ]);
    }

    /** Tạo bài trong thư viện (thuộc giảng viên demo), nội dung mẫu đã xuất bản. */
    private function makeLessons(Course $course, array $defs): array
    {
        $lessons = [];

        foreach ($defs as $key => [$title, $preview]) {
            $lesson = Lesson::create([
                'owner_id' => $this->instructor->id,
                'title' => $title,
                'content_draft' => [
                    'time' => now()->getTimestampMs(),
                    'blocks' => [
                        ['id' => 'h1', 'type' => 'header', 'data' => ['text' => $title, 'level' => 2]],
                        ['id' => 'p1', 'type' => 'paragraph', 'data' => ['text' => 'Nội dung mẫu của bài học.']],
                    ],
                    'version' => '2.31.0',
                ],
            ]);
            $lesson->publish();

            $lessons[$key] = ['lesson' => $lesson, 'preview' => $preview];
        }

        return $lessons;
    }

    private function makePaths(Course $course, array $lessons, array $defs): void
    {
        foreach ($defs as $order => $def) {
            $attributes = ['name' => $def['name'], 'is_default' => $def['default'] ?? false, 'sort_order' => $order];

            // Course::created đã tạo sẵn 1 lộ trình mặc định → dùng cho lộ trình đầu tiên
            $path = $order === 0 ? $course->learningPaths()->first() : null;
            $path ? $path->update($attributes) : ($path = $course->learningPaths()->create($attributes));

            $optionIds = collect($def['options'])->flatMap(fn ($pair) => ClassificationOption::query()
                ->where('classification_id', $pair[0]->id)
                ->whereIn('name', $pair[1])
                ->pluck('id'));
            $path->options()->sync($optionIds);

            $chapterOrder = 0;
            foreach ($def['chapters'] as $chapterTitle => $keys) {
                $chapter = $path->chapters()->create(['title' => $chapterTitle, 'sort_order' => ++$chapterOrder]);

                foreach ($keys as $lessonOrder => $key) {
                    $chapter->pathLessons()->create([
                        'lesson_id' => $lessons[$key]['lesson']->id,
                        'unlock_mode' => UnlockMode::AfterPrevious,
                        'is_preview' => $lessons[$key]['preview'],
                        'sort_order' => $lessonOrder + 1,
                    ]);
                }
            }
        }
    }
}
