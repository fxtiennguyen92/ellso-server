<?php

namespace Database\Seeders;

use App\Enums\EnrollmentSource;
use App\Models\Course;
use App\Models\LessonProgress;
use App\Models\User;
use App\Services\LearningProgress;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Việc học MẪU của học viên demo (hocvien@ellso.test) để mục "Tiếp tục khoá học" trên app có dữ liệu:
 * - 1 khoá có phí đã mua, đã học 3 bài (đang học dở)
 * - 1 khoá có phí được admin mở, chưa học bài nào (vẫn hiện — đã sở hữu)
 * - 1 khoá miễn phí đã học 1 bài (hiện)
 * - 1 khoá miễn phí mới bấm vào, chưa học (KHÔNG hiện)
 * Chạy tay:  php artisan db:seed --class=DemoMyLearningSeeder
 */
class DemoMyLearningSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction() && ! config('ellso.allow_demo_seed')) {
            throw new RuntimeException('Không chạy dữ liệu demo trên production (đặt ALLOW_DEMO_SEED=true để cho phép tạm thời).');
        }

        $student = User::where('email', 'hocvien@ellso.test')->first();
        if (! $student) {
            return;
        }
        $learning = app(LearningProgress::class);

        // Khoá 1 lộ trình, không bắt buộc đánh giá năng lực, có bài trong lộ trình
        $courses = Course::query()->where('status', 'published')->where('type', 'single')
            ->whereHas('learningPaths.pathLessons')->with('defaultPath')->orderBy('id')->get()
            ->reject(fn (Course $c) => $c->requiredPlacementTests()->isNotEmpty());
        $paid = $courses->where('price', '>', 0)->values();
        $free = $courses->where('price', 0)->values();

        $plan = array_filter([
            [$paid->get(0), EnrollmentSource::Purchase, 3, 1],
            [$paid->get(1), EnrollmentSource::Admin, 0, null],
            [$free->get(0), EnrollmentSource::Free, 1, 3],
            [$free->get(1), EnrollmentSource::Free, 0, null],
        ], fn ($p) => $p[0] !== null);

        foreach ($plan as [$course, $source, $done, $daysAgo]) {
            $enrollment = $learning->enroll($student, $course, $source, $course->defaultPath);
            $lessons = $course->defaultPath?->pathLessons()->with('lesson')->orderBy('sort_order')->get()
                ->pluck('lesson')->filter(fn ($l) => $l && ! $l->isCoaching())->take($done) ?? collect();

            foreach ($lessons as $lesson) {
                $learning->markRead($student, $lesson, $enrollment);
                // Bài mẫu có câu hỏi → đánh dấu hoàn thành thẳng (dữ liệu demo)
                LessonProgress::query()->where('user_id', $student->id)->where('lesson_id', $lesson->id)
                    ->update(['completed_at' => now()->subDays($daysAgo ?? 0)]);
            }
            if ($daysAgo !== null) {
                $enrollment->forceFill(['last_activity_at' => now()->subDays($daysAgo)])->save();
            }
        }
    }
}
