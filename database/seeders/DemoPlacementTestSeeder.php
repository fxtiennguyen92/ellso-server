<?php

namespace Database\Seeders;

use App\Enums\CourseStatus;
use App\Enums\PlacementQuestionType;
use App\Enums\UserRole;
use App\Models\Course;
use App\Models\PlacementAttempt;
use App\Models\PlacementTest;
use App\Models\User;
use App\Services\PlacementScoring;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Bài kiểm tra đầu vào MẪU + bài làm demo (cho Thống kê › Kiểm tra đầu vào).
 * Chỉ chạy tay ở local:  php artisan db:seed --class=DemoPlacementTestSeeder
 * Chạy lại sẽ thay bài mẫu cũ (cùng tên).
 */
class DemoPlacementTestSeeder extends Seeder
{
    public const TITLE = 'Bài kiểm tra đầu vào (mẫu)';

    public function run(): void
    {
        if (app()->isProduction() && ! config('ellso.allow_demo_seed')) {
            throw new RuntimeException('Không chạy dữ liệu demo trên production (đặt ALLOW_DEMO_SEED=true để cho phép tạm thời).');
        }

        PlacementTest::where('title', self::TITLE)->get()->each->delete();

        $test = PlacementTest::create([
            'title' => self::TITLE,
            'intro' => "Gồm 6 câu, mất khoảng 3 phút. Không có đáp án đúng hay sai — hãy chọn điều gần với bạn nhất.\nKết quả giúp Ellso gợi ý lộ trình phù hợp với bạn.",
            'is_active' => true,
        ]);

        $courses = Course::where('status', CourseStatus::Published)->pluck('id');

        // Phân loại + mức độ (điểm từ, cao → thấp)
        $dims = [];
        foreach ([
            'tiep_thu' => ['Khả năng tiếp thu', 'Tốc độ nắm bắt và áp dụng kiến thức mới.', [
                ['Xuất sắc', 9, 'Bạn nắm bắt kiến thức mới rất nhanh — phù hợp lộ trình nâng cao, nhịp học dày.'],
                ['Tốt', 6, 'Bạn tiếp thu tốt; lộ trình tiêu chuẩn kèm bài thực hành sẽ giúp bạn tiến nhanh.'],
                ['Khá', 3, 'Bạn cần thêm ví dụ minh hoạ; nên học từng phần nhỏ và ôn lại thường xuyên.'],
                ['Cần hỗ trợ', 0, 'Hãy bắt đầu với các bài nền tảng và nhờ mentor đồng hành trong tuần đầu.'],
            ]],
            'ky_luat' => ['Tính kỷ luật tự học', 'Khả năng duy trì thói quen học đều đặn.', [
                ['Rất cao', 9, 'Bạn có thói quen học rất đều — có thể tự học theo lộ trình dài.'],
                ['Tốt', 5, 'Bạn khá chủ động; đặt lịch nhắc học sẽ giúp bạn duy trì tốt hơn.'],
                ['Cần rèn luyện', -2, 'Hãy bắt đầu với mục tiêu nhỏ mỗi ngày (15 phút) và bật nhắc học trên app.'],
            ]],
            'kinh_nghiem' => ['Kinh nghiệm thực tế', 'Nền tảng kinh nghiệm trong lĩnh vực muốn học.', [
                ['Chuyên sâu', 6, 'Bạn đã có nhiều kinh nghiệm — nên chọn các khoá chuyên sâu, bỏ qua phần cơ bản.'],
                ['Có nền tảng', 3, 'Bạn đã có nền tảng; lộ trình trung cấp sẽ phù hợp.'],
                ['Mới bắt đầu', 0, 'Bắt đầu từ lộ trình cơ bản để xây nền vững chắc.'],
            ]],
        ] as $key => [$name, $description, $levels]) {
            $dimension = $test->dimensions()->create(['name' => $name, 'description' => $description, 'sort_order' => count($dims) + 1]);
            foreach ($levels as $i => [$levelName, $min, $comment]) {
                $level = $dimension->levels()->create(['name' => $levelName, 'min_score' => $min, 'description' => $comment, 'sort_order' => $i]);
                if ($courses->isNotEmpty() && $i === 0) {
                    $level->courses()->sync($courses->random(min(2, $courses->count())));
                }
            }
            $dims[$key] = $dimension->id;
        }

        $s = fn (array $points) => collect($points)->mapWithKeys(fn ($p, $k) => [$dims[$k] => $p])->all();

        $questions = [
            [PlacementQuestionType::Single, 'Khi học một kỹ năng mới, bạn thường mất bao lâu để áp dụng được vào công việc?', null, [
                ['Vài ngày', ['tiep_thu' => 3]],
                ['1–2 tuần', ['tiep_thu' => 2]],
                ['Khoảng 1 tháng', ['tiep_thu' => 1]],
                ['Lâu hơn 1 tháng', []],
            ]],
            [PlacementQuestionType::Single, 'Bạn đã có bao nhiêu năm kinh nghiệm trong lĩnh vực mình muốn học?', null, [
                ['Chưa có', []],
                ['Dưới 1 năm', ['kinh_nghiem' => 1]],
                ['1–3 năm', ['kinh_nghiem' => 2]],
                ['Trên 3 năm', ['kinh_nghiem' => 4]],
            ]],
            [PlacementQuestionType::Multiple, 'Bạn thường học bằng những cách nào?', 'Chọn tối đa 3 cách', [
                ['Xem video bài giảng', ['tiep_thu' => 1]],
                ['Đọc sách, tài liệu', ['tiep_thu' => 1, 'ky_luat' => 1]],
                ['Làm dự án thực tế', ['tiep_thu' => 1, 'kinh_nghiem' => 1]],
                ['Học nhóm / có mentor', ['ky_luat' => 1]],
                ['Chỉ học khi công việc cần', ['ky_luat' => -1]],
            ], 1, 3],
            [PlacementQuestionType::Ordering, 'Xếp các điều sau theo mức độ bạn ưu tiên khi học', 'Trên cùng là điều bạn ưu tiên nhất', [
                ['Hoàn thành bài học đúng hạn', ['ky_luat' => 1]],
                ['Hiểu sâu bản chất vấn đề', ['tiep_thu' => 1]],
                ['Áp dụng ngay vào công việc', ['kinh_nghiem' => 1]],
                ['Có chứng chỉ sau khoá học', []],
            ]],
            [PlacementQuestionType::Single, 'Mỗi tuần bạn dành được bao nhiêu giờ cho việc học?', null, [
                ['Dưới 2 giờ', []],
                ['2–5 giờ', ['ky_luat' => 2]],
                ['5–10 giờ', ['ky_luat' => 3]],
                ['Trên 10 giờ', ['ky_luat' => 4]],
            ]],
            [PlacementQuestionType::Single, 'Khi gặp một bài khó, bạn thường làm gì?', null, [
                ['Tạm bỏ qua, quay lại sau', ['ky_luat' => -1]],
                ['Hỏi ngay người khác', ['tiep_thu' => 1]],
                ['Tự tìm hiểu trước rồi mới hỏi', ['tiep_thu' => 2, 'ky_luat' => 2]],
            ]],
        ];

        foreach ($questions as $i => $q) {
            [$type, $prompt, $hint, $options] = $q;
            $question = $test->questions()->create([
                'type' => $type,
                'prompt' => $prompt,
                'hint' => $hint,
                'min_choices' => $q[4] ?? null,
                'max_choices' => $q[5] ?? null,
                'sort_order' => $i + 1,
            ]);
            foreach ($options as $j => [$label, $points]) {
                $question->options()->create(['label' => $label, 'scores' => $s($points), 'sort_order' => $j + 1]);
            }
        }

        $this->seedAttempts($test->fresh()->loadStructure());
        $this->command?->info('Đã tạo "' . self::TITLE . '" (đang dùng) + bài làm demo.');
    }

    /** Bài làm ngẫu nhiên cho học viên demo (@demo.ellso.test), rải trong 60 ngày. */
    private function seedAttempts(PlacementTest $test): void
    {
        $students = User::where('role', UserRole::Student)->where('email', 'like', '%@demo.ellso.test')->get();
        if ($students->isEmpty()) {
            $students = collect(range(1, 40))->map(fn (int $i) => User::firstOrCreate(
                ['email' => "hocvien{$i}@demo.ellso.test"],
                ['name' => "Học viên demo {$i}", 'password' => 'password', 'role' => UserRole::Student, 'is_active' => true, 'password_changed_at' => now()],
            ));
        }

        $scoring = app(PlacementScoring::class);

        foreach ($students as $student) {
            if (random_int(1, 100) > 80) {
                continue; // ~20% chưa làm bài
            }
            $answers = [];
            foreach ($test->questions as $question) {
                $ids = $question->options->pluck('id')->shuffle()->values();
                $answers[$question->id] = match ($question->type) {
                    PlacementQuestionType::Single => [$ids->first()],
                    PlacementQuestionType::Multiple => $ids->take(random_int(...$question->choiceBounds()))->all(),
                    PlacementQuestionType::Ordering => $ids->all(),
                };
            }

            $attempt = $scoring->submit($student, $test, $answers);
            $attempt->forceFill(['completed_at' => now()->subMinutes(random_int(0, 60 * 24 * 60))])->save();
        }

        PlacementAttempt::query()->whereNull('completed_at')->delete();
    }
}
