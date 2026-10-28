<?php

namespace Database\Seeders;

use App\Enums\EnrollmentSource;
use App\Enums\UserRole;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\LessonQuizAnswer;
use App\Models\User;
use App\Services\LearningProgress;
use App\Support\LessonContent;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Dữ liệu DEMO cho Học bạ: thêm câu hỏi trắc nghiệm vào bài học đầu của mỗi lộ trình,
 * ghi danh học viên demo (@demo.ellso.test) vào các khoá và mô phỏng tiến độ học.
 * Chỉ chạy tay ở local:  php artisan db:seed --class=DemoLearningSeeder (chạy lại sẽ làm mới tiến độ demo)
 */
class DemoLearningSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction() && ! config('ellso.allow_demo_seed')) {
            throw new RuntimeException('Không chạy dữ liệu demo trên production (đặt ALLOW_DEMO_SEED=true để cho phép tạm thời).');
        }

        $progress = app(LearningProgress::class);
        $courses = Course::query()->with('learningPaths')->get()->filter(fn (Course $c) => $c->learningPaths->isNotEmpty());
        if ($courses->isEmpty()) {
            $this->command?->warn('Chưa có khoá học có lộ trình.');

            return;
        }

        // 1. Câu hỏi mẫu cho bài đầu của mỗi lộ trình (nếu bài chưa có câu hỏi)
        foreach ($courses as $course) {
            foreach ($course->learningPaths as $path) {
                $first = $progress->pathItems($path)->first()?->lesson;
                if ($first && LessonContent::quizzes($first->content_published) === []) {
                    $this->addQuiz($first);
                }
            }
        }

        // 2. Ghi danh + tiến độ cho học viên demo
        $students = User::query()->where('role', UserRole::Student)->where('email', 'like', '%@demo.ellso.test')->get();
        CourseEnrollment::query()->whereIn('user_id', $students->pluck('id'))->delete();
        LessonProgress::query()->whereIn('user_id', $students->pluck('id'))->delete();
        LessonQuizAnswer::query()->whereIn('user_id', $students->pluck('id'))->delete();

        foreach ($students as $i => $student) {
            foreach ($courses->shuffle()->take(random_int(0, min(3, $courses->count()))) as $course) {
                $path = random_int(1, 10) > 2 ? $course->learningPaths->random() : null;
                $enrollment = $progress->enroll($student, $course, collect(EnrollmentSource::cases())->random(), $path, null, null);
                $enrollment->forceFill(['enrolled_at' => now()->subDays(random_int(5, 90))])->save();

                if (! $path) {
                    continue;
                }
                $items = $progress->pathItems($path);
                $learned = random_int(0, $items->count());
                foreach ($items->take($learned) as $item) {
                    $this->learn($progress, $student, $item->lesson, $enrollment);
                }
                if ($learned) {
                    $enrollment->forceFill(['last_activity_at' => now()->subHours(random_int(1, 24 * 20))])->save();
                }
            }
        }

        $this->command?->info('Đã tạo câu hỏi mẫu + ghi danh / tiến độ cho ' . $students->count() . ' học viên demo.');
    }

    private function learn(LearningProgress $progress, User $student, Lesson $lesson, CourseEnrollment $enrollment): void
    {
        foreach (LessonContent::quizzes($lesson->content_published) as $blockId => $quiz) {
            $correct = collect($quiz['options'])->where('correct', true)->pluck('id')->all();
            $wrong = collect($quiz['options'])->where('correct', false)->pluck('id')->all();
            if ($wrong && random_int(0, 1)) {
                $progress->answer($student, $lesson, $blockId, [$wrong[0]], $enrollment); // sai một lần
            }
            $progress->answer($student, $lesson, $blockId, $correct, $enrollment);
        }
        $progress->markRead($student, $lesson, $enrollment);
    }

    private function addQuiz(Lesson $lesson): void
    {
        $content = $lesson->content_published ?? ['blocks' => []];
        $content['blocks'][] = ['id' => 'demoq1', 'type' => 'quiz', 'data' => [
            'type' => 'single',
            'question' => 'Ý nào mô tả đúng nội dung chính của bài học này?',
            'options' => [
                ['id' => 'a', 'text' => 'Nắm khái niệm cốt lõi và áp dụng vào ví dụ', 'correct' => true],
                ['id' => 'b', 'text' => 'Chỉ cần ghi nhớ định nghĩa', 'correct' => false],
                ['id' => 'c', 'text' => 'Bỏ qua phần ví dụ', 'correct' => false],
            ],
            'explanation' => 'Bài học tập trung vào hiểu khái niệm và áp dụng.',
        ]];
        $content['blocks'][] = ['id' => 'demoq2', 'type' => 'quiz', 'data' => [
            'type' => 'multiple',
            'question' => 'Chọn các bước nên làm sau khi học xong bài:',
            'options' => [
                ['id' => 'a', 'text' => 'Làm lại ví dụ trong bài', 'correct' => true],
                ['id' => 'b', 'text' => 'Ghi chú điểm chính', 'correct' => true],
                ['id' => 'c', 'text' => 'Bỏ qua phần câu hỏi', 'correct' => false],
            ],
            'explanation' => '',
        ]];
        $lesson->forceFill(['content_draft' => $content, 'content_published' => $content])->saveQuietly();
    }
}
