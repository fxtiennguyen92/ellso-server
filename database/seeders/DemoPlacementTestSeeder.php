<?php

namespace Database\Seeders;

use App\Enums\CourseStatus;
use App\Enums\PlacementQuestionType;
use App\Enums\UserRole;
use App\Models\Course;
use App\Models\PlacementTest;
use App\Models\User;
use App\Services\PlacementScoring;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * 2 bài kiểm tra đầu vào MẪU (chạy song song, mỗi bài một nhóm năng lực) + bài làm demo.
 *  1. "Bài kiểm tra đầu vào (mẫu)": 6 câu, làm hết, thứ tự soạn.
 *  2. "Tư duy & cách làm việc (mẫu)": ngân hàng 20 câu, mỗi lần rút ngẫu nhiên 8 câu, đảo đáp án.
 * Chạy tay:  php artisan db:seed --class=DemoPlacementTestSeeder
 * Chạy lại sẽ thay các bài mẫu cũ (cùng tên).
 */
class DemoPlacementTestSeeder extends Seeder
{
    public const TITLE = 'Bài kiểm tra đầu vào (mẫu)';

    public const TITLE_2 = 'Tư duy & cách làm việc (mẫu)';

    public function run(): void
    {
        if (app()->isProduction() && ! config('ellso.allow_demo_seed')) {
            throw new RuntimeException('Không chạy dữ liệu demo trên production (đặt ALLOW_DEMO_SEED=true để cho phép tạm thời).');
        }

        PlacementTest::whereIn('title', [self::TITLE, self::TITLE_2])->get()->each->delete();

        $this->learningTest();
        $this->thinkingTest();
        $this->command?->info('Đã tạo 2 bài mẫu (đang dùng): "' . self::TITLE . '", "' . self::TITLE_2 . '" + bài làm demo.');
    }

    /** Bài 1: Tiếp thu / Kỷ luật / Kinh nghiệm — 6 câu, làm hết. */
    private function learningTest(): void
    {
        $test = PlacementTest::create([
            'title' => self::TITLE,
            'intro' => "Gồm 6 câu, mất khoảng 3 phút. Không có đáp án đúng hay sai — hãy chọn điều gần với bạn nhất.\nKết quả giúp Ellso gợi ý lộ trình phù hợp với bạn.",
            'shuffle_options' => false,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        // Năng lực + mức độ (đạt từ %, cao → thấp)
        $dims = $this->dimensions($test, [
            'tiep_thu' => ['Khả năng tiếp thu', 'Tốc độ nắm bắt và áp dụng kiến thức mới.', [
                ['Xuất sắc', 75, 'Bạn nắm bắt kiến thức mới rất nhanh — phù hợp lộ trình nâng cao, nhịp học dày.'],
                ['Tốt', 50, 'Bạn tiếp thu tốt; lộ trình tiêu chuẩn kèm bài thực hành sẽ giúp bạn tiến nhanh.'],
                ['Khá', 25, 'Bạn cần thêm ví dụ minh hoạ; nên học từng phần nhỏ và ôn lại thường xuyên.'],
                ['Cần hỗ trợ', 0, 'Hãy bắt đầu với các bài nền tảng và nhờ mentor đồng hành trong tuần đầu.'],
            ]],
            'ky_luat' => ['Tính kỷ luật tự học', 'Khả năng duy trì thói quen học đều đặn.', [
                ['Rất cao', 70, 'Bạn có thói quen học rất đều — có thể tự học theo lộ trình dài.'],
                ['Tốt', 40, 'Bạn khá chủ động; đặt lịch nhắc học sẽ giúp bạn duy trì tốt hơn.'],
                ['Cần rèn luyện', 0, 'Hãy bắt đầu với mục tiêu nhỏ mỗi ngày (15 phút) và bật nhắc học trên app.'],
            ]],
            'kinh_nghiem' => ['Kinh nghiệm thực tế', 'Nền tảng kinh nghiệm trong lĩnh vực muốn học.', [
                ['Chuyên sâu', 65, 'Bạn đã có nhiều kinh nghiệm — nên chọn các khoá chuyên sâu, bỏ qua phần cơ bản.'],
                ['Có nền tảng', 35, 'Bạn đã có nền tảng; lộ trình trung cấp sẽ phù hợp.'],
                ['Mới bắt đầu', 0, 'Bắt đầu từ lộ trình cơ bản để xây nền vững chắc.'],
            ]],
        ]);

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

        $this->questions($test, $questions, $s);
        $this->seedAttempts($test);
    }

    /** Bài 2: Tư duy logic / Sáng tạo / Làm việc nhóm — ngân hàng 20 câu, mỗi lần rút 8 câu, đảo đáp án. */
    private function thinkingTest(): void
    {
        $test = PlacementTest::create([
            'title' => self::TITLE_2,
            'intro' => "Gồm 8 câu, mất khoảng 4 phút. Mỗi lần làm, câu hỏi được chọn ngẫu nhiên.\nKhông có đáp án đúng hay sai — hãy chọn cách bạn thường làm nhất.",
            'question_count' => 8,
            'shuffle_options' => true,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $dims = $this->dimensions($test, [
            'logic' => ['Tư duy logic', 'Phân tích vấn đề, suy luận có hệ thống.', [
                ['Rất tốt', 75, 'Bạn phân tích vấn đề rất mạch lạc — phù hợp các khoá kỹ thuật, dữ liệu, chuyên sâu.'],
                ['Tốt', 45, 'Bạn suy luận khá chắc; thêm bài tập tình huống sẽ giúp bạn nhanh hơn.'],
                ['Cần luyện thêm', 0, 'Nên bắt đầu với các bài có ví dụ từng bước và sơ đồ minh hoạ.'],
            ]],
            'sang_tao' => ['Sáng tạo', 'Đưa ra ý tưởng mới, cách làm khác.', [
                ['Nổi bật', 70, 'Bạn có nhiều ý tưởng mới — thử các khoá thiết kế, marketing, khởi nghiệp.'],
                ['Khá', 40, 'Bạn sáng tạo khi có gợi ý; các bài brainstorm theo khung sẽ hợp với bạn.'],
                ['Thiên về quy trình', 0, 'Bạn thích làm theo cách đã kiểm chứng — lộ trình có hướng dẫn rõ ràng sẽ phù hợp.'],
            ]],
            'nhom' => ['Làm việc nhóm', 'Phối hợp, chia sẻ và hỗ trợ người khác.', [
                ['Rất tốt', 70, 'Bạn phối hợp rất tốt — nên chọn khoá có dự án nhóm và vai trò dẫn dắt.'],
                ['Tốt', 40, 'Bạn làm nhóm ổn; thêm kỹ năng giao tiếp sẽ giúp bạn tiến xa hơn.'],
                ['Thích làm độc lập', 0, 'Bạn làm việc hiệu quả nhất khi tự chủ — lộ trình tự học sẽ hợp với bạn.'],
            ]],
        ]);
        $s = fn (array $points) => collect($points)->mapWithKeys(fn ($p, $k) => [$dims[$k] => $p])->all();
        $single = PlacementQuestionType::Single;

        $this->questions($test, [
            [$single, 'Khi nhận một nhiệm vụ mới chưa rõ cách làm, việc đầu tiên bạn làm là gì?', null, [
                ['Chia nhỏ nhiệm vụ thành các bước', ['logic' => 3]],
                ['Nghĩ thử vài cách làm khác nhau', ['sang_tao' => 3]],
                ['Hỏi đồng nghiệp đã từng làm', ['nhom' => 3]],
                ['Bắt tay làm luôn rồi sửa dần', ['sang_tao' => 1]],
            ]],
            [$single, 'Số tiếp theo của dãy 2, 4, 8, 16, … là gì?', null, [
                ['24', []],
                ['32', ['logic' => 3]],
                ['30', []],
                ['18', []],
            ]],
            [$single, 'Nhóm bạn bất đồng ý kiến trong cuộc họp. Bạn thường:', null, [
                ['Lắng nghe hết rồi tổng hợp điểm chung', ['nhom' => 3, 'logic' => 1]],
                ['Đưa ra phương án thứ ba', ['sang_tao' => 2, 'nhom' => 1]],
                ['Bảo vệ ý kiến mình bằng số liệu', ['logic' => 2]],
                ['Im lặng, để người khác quyết', []],
            ]],
            [$single, 'Bạn được giao làm lại một báo cáo đã có mẫu sẵn. Bạn sẽ:', null, [
                ['Dùng đúng mẫu cũ', []],
                ['Giữ mẫu nhưng thêm biểu đồ cho dễ đọc', ['sang_tao' => 2]],
                ['Đề xuất cách trình bày hoàn toàn mới', ['sang_tao' => 3]],
                ['Hỏi người đọc cần gì rồi mới làm', ['nhom' => 2, 'logic' => 1]],
            ]],
            [$single, 'Tất cả mèo đều biết bơi. Tom là mèo. Vậy:', null, [
                ['Tom biết bơi', ['logic' => 3]],
                ['Tom có thể không biết bơi', []],
                ['Không kết luận được', ['logic' => 1]],
            ]],
            [$single, 'Khi đồng đội làm chậm làm ảnh hưởng tiến độ chung, bạn:', null, [
                ['Hỏi xem họ gặp khó khăn gì và giúp', ['nhom' => 3]],
                ['Báo trưởng nhóm', ['nhom' => 1]],
                ['Tự làm luôn phần của họ', ['nhom' => -1]],
                ['Đề xuất chia lại việc cho hợp lý', ['logic' => 2, 'nhom' => 1]],
            ]],
            [$single, 'Bạn thích kiểu bài tập nào nhất?', null, [
                ['Giải đố, tìm quy luật', ['logic' => 3]],
                ['Thiết kế, viết, sáng tác', ['sang_tao' => 3]],
                ['Thảo luận nhóm, đóng vai', ['nhom' => 3]],
            ]],
            [$single, 'Một quy trình ở công ty tốn thời gian. Bạn sẽ:', null, [
                ['Đo xem bước nào tốn thời gian nhất', ['logic' => 3]],
                ['Nghĩ cách làm khác hẳn', ['sang_tao' => 3]],
                ['Gom ý kiến mọi người rồi cùng sửa', ['nhom' => 3]],
                ['Vẫn làm theo quy trình cũ', []],
            ]],
            [$single, 'Nếu A lớn hơn B và B lớn hơn C, thì:', null, [
                ['C lớn hơn A', []],
                ['A lớn hơn C', ['logic' => 3]],
                ['A bằng C', []],
            ]],
            [$single, 'Khi brainstorm, bạn thường:', null, [
                ['Đưa ra nhiều ý tưởng, kể cả ý lạ', ['sang_tao' => 3]],
                ['Đánh giá ý nào khả thi', ['logic' => 2]],
                ['Khuyến khích người khác nói', ['nhom' => 3]],
                ['Chờ người khác đưa ý trước', []],
            ]],
            [$single, 'Bạn nhận được phản hồi chê sản phẩm của mình. Bạn:', null, [
                ['Hỏi lại cụ thể chỗ nào chưa ổn', ['logic' => 2, 'nhom' => 1]],
                ['Thử làm một phiên bản khác hẳn', ['sang_tao' => 2]],
                ['Cảm ơn và thảo luận cùng nhóm', ['nhom' => 2]],
                ['Thấy khó chịu, bỏ qua', []],
            ]],
            [$single, 'Trong một dự án nhóm, bạn hay nhận vai:', null, [
                ['Lên kế hoạch, chia việc', ['logic' => 2, 'nhom' => 2]],
                ['Nghĩ ý tưởng chính', ['sang_tao' => 3]],
                ['Kết nối, giữ tinh thần cả nhóm', ['nhom' => 3]],
                ['Làm phần kỹ thuật một mình', ['logic' => 1]],
            ]],
            [PlacementQuestionType::Multiple, 'Những việc nào bạn thấy hứng thú?', 'Chọn tối đa 2 việc', [
                ['Phân tích số liệu', ['logic' => 2]],
                ['Vẽ, thiết kế, quay dựng', ['sang_tao' => 2]],
                ['Tổ chức sự kiện cùng mọi người', ['nhom' => 2]],
                ['Viết quy trình, hướng dẫn', ['logic' => 1]],
                ['Nghĩ tên, slogan cho sản phẩm', ['sang_tao' => 1]],
            ], 1, 2],
            [PlacementQuestionType::Multiple, 'Khi học online, điều gì giúp bạn học tốt hơn?', 'Chọn tối đa 2 điều', [
                ['Có bài tập logic sau mỗi bài', ['logic' => 2]],
                ['Được tự làm sản phẩm của riêng mình', ['sang_tao' => 2]],
                ['Có nhóm học cùng', ['nhom' => 2]],
                ['Không cần gì thêm', []],
            ], 1, 2],
            [PlacementQuestionType::Multiple, 'Bạn thường dùng công cụ nào khi làm việc?', 'Chọn tối đa 3 công cụ', [
                ['Bảng tính (Excel, Sheets)', ['logic' => 1]],
                ['Công cụ thiết kế (Canva, Figma)', ['sang_tao' => 1]],
                ['Công cụ chat nhóm (Slack, Zalo nhóm)', ['nhom' => 1]],
                ['Sơ đồ tư duy (mind map)', ['sang_tao' => 1, 'logic' => 1]],
                ['Lịch chung, giao việc (Trello…)', ['nhom' => 1, 'logic' => 1]],
            ], 1, 3],
            [PlacementQuestionType::Ordering, 'Xếp các yếu tố theo mức quan trọng khi bạn chọn cách giải quyết vấn đề', 'Trên cùng là quan trọng nhất', [
                ['Có số liệu chứng minh', ['logic' => 1]],
                ['Mới lạ, khác biệt', ['sang_tao' => 1]],
                ['Được mọi người đồng thuận', ['nhom' => 1]],
            ]],
            [PlacementQuestionType::Ordering, 'Xếp các hoạt động theo mức bạn thích', 'Trên cùng là thích nhất', [
                ['Giải bài toán khó', ['logic' => 1]],
                ['Làm một sản phẩm thủ công / sáng tác', ['sang_tao' => 1]],
                ['Đi chơi, làm việc cùng nhóm bạn', ['nhom' => 1]],
                ['Nghỉ ngơi một mình', []],
            ]],
            [$single, 'Bạn phải chọn giữa 2 nhà cung cấp. Bạn dựa vào:', null, [
                ['Bảng so sánh giá, chất lượng', ['logic' => 3]],
                ['Cảm nhận và ý tưởng hợp tác lâu dài', ['sang_tao' => 1]],
                ['Ý kiến của những người sẽ dùng', ['nhom' => 2]],
            ]],
            [$single, 'Bạn có ý tưởng mới cho nhóm nhưng chưa chắc chắn. Bạn:', null, [
                ['Làm thử nhỏ rồi mới đưa ra', ['logic' => 1, 'sang_tao' => 2]],
                ['Chia sẻ ngay để cả nhóm góp ý', ['nhom' => 2, 'sang_tao' => 1]],
                ['Giữ lại, chờ khi chắc chắn', []],
            ]],
            [$single, 'Một máy làm 1 sản phẩm trong 5 phút. 5 máy làm 5 sản phẩm mất bao lâu?', null, [
                ['5 phút', ['logic' => 3]],
                ['25 phút', []],
                ['1 phút', []],
            ]],
        ], $s);

        $this->seedAttempts($test);
    }

    /**
     * Tạo năng lực + mức độ (ngưỡng %), mức cao nhất gợi ý khoá đã xuất bản.
     *
     * @return array<string, int> key => dimension id
     */
    private function dimensions(PlacementTest $test, array $definitions): array
    {
        $courses = Course::where('status', CourseStatus::Published)->pluck('id');
        $dims = [];

        foreach ($definitions as $key => [$name, $description, $levels]) {
            $dimension = $test->dimensions()->create(['name' => $name, 'description' => $description, 'sort_order' => count($dims) + 1]);
            foreach ($levels as $i => [$levelName, $min, $comment]) {
                $level = $dimension->levels()->create(['name' => $levelName, 'min_percent' => $min, 'description' => $comment, 'sort_order' => $i]);
                if ($courses->isNotEmpty() && $i === 0) {
                    $level->courses()->sync($courses->random(min(2, $courses->count())));
                }
            }
            $dims[$key] = $dimension->id;
        }

        return $dims;
    }

    /** @param  list<array>  $questions  [type, prompt, hint, [[label, points]], min?, max?] */
    private function questions(PlacementTest $test, array $questions, callable $s): void
    {
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
    }

    /** Bài làm ngẫu nhiên cho học viên demo (@demo.ellso.test), rải trong 60 ngày; bài rút câu → mỗi người một bộ câu. */
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
        $test = $test->fresh();

        foreach ($students as $student) {
            if (random_int(1, 100) > 75) {
                continue; // ~25% chưa làm bài
            }
            $drawn = $scoring->testFor($student, $test);
            $answers = [];
            foreach ($drawn->questions as $question) {
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
    }
}
