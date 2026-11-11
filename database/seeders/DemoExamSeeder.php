<?php

namespace Database\Seeders;

use App\Enums\UnlockMode;
use App\Models\Course;
use App\Models\Exam;
use App\Models\Lesson;
use App\Models\PathChapter;
use App\Models\PathLesson;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Ngân hàng đề thi MẪU + đặt vào lộ trình của các khoá đã có:
 * - "Thi giữa kỳ (mẫu)": 15 câu, mỗi lần rút 8, không giới hạn thời gian → đặt sau chương 1.
 * - "Thi cuối kỳ (mẫu)": 20 câu, mỗi lần rút 10, 20 phút → đặt cuối lộ trình.
 * - Một bài Coaching "Trao đổi trực tiếp" ở cuối chương 1.
 * Chạy:  php artisan db:seed --class=DemoExamSeeder   (chạy lại sẽ thay đề mẫu cũ)
 */
class DemoExamSeeder extends Seeder
{
    public const MIDTERM = 'Thi giữa kỳ (mẫu)';

    public const FINAL = 'Thi cuối kỳ (mẫu)';

    public function run(): void
    {
        if (app()->isProduction() && ! config('ellso.allow_demo_seed')) {
            throw new RuntimeException('Không chạy dữ liệu demo trên production (đặt ALLOW_DEMO_SEED=true để cho phép tạm thời).');
        }

        // Dọn dữ liệu mẫu cũ
        $old = Exam::whereIn('title', [self::MIDTERM, self::FINAL])->pluck('id');
        PathChapter::whereIn('exam_id', $old)->delete();
        Exam::whereIn('id', $old)->get()->each->delete();
        Lesson::where('kind', Lesson::KIND_COACHING)->where('summary', 'demo')->get()->each(function (Lesson $lesson) {
            $lesson->pathLessons()->delete();
            $lesson->forceDelete();
        });

        $midterm = $this->exam(self::MIDTERM, 'Kiểm tra kiến thức nửa đầu khoá. Đậu khi đúng từ 70% trở lên, được làm lại.', 8, null, 15);
        $final = $this->exam(self::FINAL, "Bài thi cuối khoá, 10 câu trong 20 phút. Hết giờ bài tự nộp.\nĐậu bài này (≥ 70%) để được cấp chứng chỉ.", 10, 20, 20);

        foreach (Course::with('learningPaths.chapters')->get() as $course) {
            foreach ($course->learningPaths as $path) {
                $chapters = $path->chapters->where('kind', PathChapter::KIND_CHAPTER)->sortBy('sort_order')->values();
                if ($chapters->isEmpty()) {
                    continue;
                }

                // Coaching cuối chương 1
                $first = $chapters->first();
                $coaching = Lesson::create(['kind' => Lesson::KIND_COACHING, 'summary' => 'demo', 'owner_id' => $course->instructor_id]);
                PathLesson::create([
                    'path_chapter_id' => $first->id,
                    'lesson_id' => $coaching->id,
                    'unlock_mode' => UnlockMode::AfterPrevious,
                    'sort_order' => (int) $first->pathLessons()->max('sort_order') + 1,
                ]);

                // Giữa kỳ sau chương 1 (khi có từ 2 chương), cuối kỳ ở cuối
                $order = 0;
                foreach ($chapters as $i => $chapter) {
                    $chapter->update(['sort_order' => ++$order]);
                    if ($i === 0 && $chapters->count() > 1) {
                        $path->chapters()->create(['kind' => PathChapter::KIND_EXAM, 'exam_id' => $midterm->id, 'title' => 'Thi giữa kỳ', 'sort_order' => ++$order]);
                    }
                }
                $path->chapters()->create(['kind' => PathChapter::KIND_EXAM, 'exam_id' => $final->id, 'title' => 'Thi cuối kỳ', 'sort_order' => ++$order]);
            }
        }

        $this->command?->info('Đã tạo 2 đề thi mẫu và đặt vào lộ trình của '.Course::count().' khoá (kèm 1 bài coaching mỗi lộ trình).');
    }

    private function exam(string $title, string $intro, int $draw, ?int $minutes, int $count): Exam
    {
        $exam = Exam::create(['title' => $title, 'intro' => $intro, 'question_count' => $draw, 'time_limit_minutes' => $minutes, 'shuffle_options' => true]);

        $bank = [
            ['single', 'AI tạo sinh (Generative AI) dùng để làm gì?', ['Tạo nội dung mới như văn bản, hình ảnh, mã' => true, 'Chỉ để lưu trữ dữ liệu' => false, 'Thay thế hoàn toàn trình duyệt web' => false, 'Tăng tốc độ mạng' => false]],
            ['single', 'HTML dùng để làm gì trên trang web?', ['Mô tả cấu trúc nội dung' => true, 'Tạo hiệu ứng chuyển động' => false, 'Lưu dữ liệu người dùng' => false]],
            ['single', 'CSS phụ trách phần nào của trang web?', ['Giao diện, màu sắc, bố cục' => true, 'Xử lý thanh toán' => false, 'Cơ sở dữ liệu' => false]],
            ['multiple', 'Những yếu tố nào giúp prompt cho AI rõ ràng hơn?', ['Nêu rõ mục tiêu' => true, 'Cho ví dụ mẫu' => true, 'Viết càng ngắn càng tốt, không cần bối cảnh' => false, 'Nêu định dạng kết quả mong muốn' => true]],
            ['single', 'Tên miền (domain) là gì?', ['Địa chỉ dễ nhớ của website' => true, 'Một loại ngôn ngữ lập trình' => false, 'Phần mềm soạn thảo' => false]],
            ['single', 'Khi AI trả lời sai, bạn nên làm gì trước tiên?', ['Kiểm tra lại và cung cấp thêm bối cảnh' => true, 'Tin ngay câu trả lời' => false, 'Ngừng dùng AI' => false]],
            ['multiple', 'Đâu là công cụ dùng để chia sẻ code với nhóm?', ['GitHub' => true, 'GitLab' => true, 'Photoshop' => false]],
            ['single', '"Responsive" nghĩa là trang web…', ['Hiển thị tốt trên nhiều kích thước màn hình' => true, 'Tải nhanh hơn 2 lần' => false, 'Có nhiều màu sắc' => false]],
            ['single', 'Hosting là gì?', ['Nơi lưu trữ và chạy website trên internet' => true, 'Phần mềm thiết kế logo' => false, 'Một loại trình duyệt' => false]],
            ['single', 'SSL/HTTPS giúp website…', ['Mã hoá dữ liệu giữa người dùng và máy chủ' => true, 'Tăng số lượng trang' => false, 'Đổi màu giao diện' => false]],
            ['multiple', 'Những thông tin nào KHÔNG nên đưa vào prompt gửi cho AI công cộng?', ['Mật khẩu' => true, 'Số thẻ ngân hàng' => true, 'Câu hỏi chung về HTML' => false]],
            ['single', 'Landing page thường dùng để…', ['Thu hút khách hàng thực hiện một hành động cụ thể' => true, 'Lưu trữ tệp tin nội bộ' => false, 'Chạy ứng dụng máy tính' => false]],
            ['single', 'Bước nào nên làm trước khi đưa website lên mạng?', ['Kiểm tra trên điện thoại và máy tính' => true, 'Xoá toàn bộ nội dung' => false, 'Tắt HTTPS' => false]],
            ['single', 'JavaScript dùng để…', ['Thêm tương tác, xử lý logic trên trang' => true, 'Đặt tên miền' => false, 'In ấn tài liệu' => false]],
            ['multiple', 'Cách nào giúp website tải nhanh hơn?', ['Nén ảnh' => true, 'Dùng CDN' => true, 'Thêm thật nhiều video tự phát' => false]],
            ['single', 'SEO là gì?', ['Tối ưu để website dễ được tìm thấy trên công cụ tìm kiếm' => true, 'Một loại máy chủ' => false, 'Phần mềm diệt virus' => false]],
            ['single', 'Khi dùng code do AI tạo ra, bạn nên…', ['Đọc hiểu và chạy thử trước khi dùng' => true, 'Dán thẳng lên website thật' => false, 'Không cần kiểm tra' => false]],
            ['single', 'Favicon là gì?', ['Biểu tượng nhỏ hiện trên tab trình duyệt' => true, 'Tên công ty' => false, 'Phông chữ chính' => false]],
            ['multiple', 'Những gì nên có ở cuối trang (footer) của website doanh nghiệp?', ['Thông tin liên hệ' => true, 'Chính sách bảo mật' => true, 'Mật khẩu quản trị' => false]],
            ['single', 'Form liên hệ trên website cần…', ['Kiểm tra dữ liệu nhập và chống spam' => true, 'Không cần nút gửi' => false, 'Hiện mật khẩu người dùng' => false]],
        ];

        foreach (array_slice($bank, 0, $count) as $i => [$type, $prompt, $options]) {
            $question = $exam->questions()->create(['type' => $type, 'prompt' => $prompt, 'sort_order' => $i + 1]);
            $j = 0;
            foreach ($options as $label => $correct) {
                $question->options()->create(['label' => $label, 'is_correct' => $correct, 'sort_order' => ++$j]);
            }
        }

        return $exam;
    }
}
