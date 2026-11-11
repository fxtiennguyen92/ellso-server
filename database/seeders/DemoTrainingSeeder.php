<?php

namespace Database\Seeders;

use App\Enums\TrainingCourseStatus;
use App\Enums\UserRole;
use App\Models\TrainingCourse;
use App\Models\TrainingLocation;
use App\Models\TrainingSession;
use App\Models\User;
use App\Support\MediaStorage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * 2 chương trình huấn luyện MẪU (nhiều buổi):
 *  - "Kỹ năng quản lý đội nhóm": 3 buổi (2 buổi ở Cafe A, 1 buổi ở Cafe B), 1.500.000 ₫, tối đa 20, trailer YouTube.
 *  - "Tư duy phản biện cho người đi làm": 2 buổi ở Cafe A, miễn phí với gói thành viên, tối đa 12.
 * Chạy tay:  php artisan db:seed --class=DemoTrainingSeeder   (chạy lại sẽ thay chương trình mẫu cùng tên)
 */
class DemoTrainingSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction() && ! config('ellso.allow_demo_seed')) {
            throw new RuntimeException('Không chạy dữ liệu demo trên production (đặt ALLOW_DEMO_SEED=true để cho phép tạm thời).');
        }

        $cafeA = TrainingLocation::firstOrCreate(['label' => 'Cafe A – Quận 1'], [
            'address' => '12 Nguyễn Huệ, Phường Bến Nghé, Quận 1, TP.HCM',
            'map_url' => 'https://maps.google.com/?q=12+Nguyen+Hue+Quan+1',
            'note' => 'Tầng 2, phòng họp nhỏ',
        ]);
        $cafeB = TrainingLocation::firstOrCreate(['label' => 'Cafe B – Bình Thạnh'], [
            'address' => '88 Xô Viết Nghệ Tĩnh, Phường 21, Bình Thạnh, TP.HCM',
            'note' => 'Gửi xe phía sau quán',
        ]);

        $titles = ['Kỹ năng quản lý đội nhóm', 'Tư duy phản biện cho người đi làm'];
        TrainingCourse::whereIn('title', $titles)->get()->each->forceDelete();
        $trainerId = User::where('role', UserRole::Instructor)->value('id');
        $at = fn (int $days, string $time) => CarbonImmutable::parse(now('Asia/Ho_Chi_Minh')->addDays($days)->toDateString().' '.$time, 'Asia/Ho_Chi_Minh')->utc();

        $team = TrainingCourse::create([
            'title' => $titles[0],
            'summary' => 'Chương trình 3 buổi giúp trưởng nhóm mới giao việc, theo dõi và phản hồi hiệu quả.',
            'banner_path' => $this->banner('trainings/banners/demo-quan-ly.png', [245, 158, 11]),
            'trailer_youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'price' => 1_500_000,
            'capacity' => 20,
            'trainer_id' => $trainerId,
            'status' => TrainingCourseStatus::Published,
            'content' => [
                ['type' => 'paragraph', 'data' => ['text' => 'Dành cho người vừa lên vị trí quản lý hoặc chuẩn bị dẫn dắt nhóm **3–10 người**. Học qua tình huống thực tế, thực hành theo cặp.']],
                ['type' => 'steps', 'data' => ['title' => 'Nội dung các buổi', 'items' => ['Buổi 1: Giao việc rõ ràng với khung 5W1H', 'Buổi 2: Họp 1:1 hiệu quả trong 15 phút', 'Buổi 3: Phản hồi để nhân viên tiến bộ, không mất động lực']]],
                ['type' => 'bullets', 'data' => ['title' => 'Phù hợp với', 'items' => ['Trưởng nhóm, quản lý cấp 1', 'Nhân viên sắp được đề bạt']]],
                ['type' => 'callout', 'data' => ['tone' => 'info', 'title' => 'Mang theo', 'text' => 'Sổ tay và một tình huống khó bạn đang gặp với nhóm.']],
            ],
        ]);
        foreach ([[20, $cafeA, '18:00', '21:00', null], [22, $cafeA, '18:00', '21:00', null], [27, $cafeB, '08:30', '11:30', 'Buổi cuối có xuất ăn sáng nhẹ']] as [$day, $place, $from, $to, $note]) {
            TrainingSession::create(['training_course_id' => $team->id, 'training_location_id' => $place->id, 'starts_at' => $at($day, $from), 'ends_at' => $at($day, $to), 'note' => $note]);
        }

        $critical = TrainingCourse::create([
            'title' => $titles[1],
            'summary' => '2 buổi tối thực hành đặt câu hỏi, đánh giá lập luận và ra quyết định dựa trên dữ kiện.',
            'banner_path' => $this->banner('trainings/banners/demo-tu-duy.png', [99, 102, 241]),
            'price' => 0,
            'capacity' => 12,
            'trainer_id' => $trainerId,
            'status' => TrainingCourseStatus::Published,
            'content' => [
                ['type' => 'paragraph', 'data' => ['text' => 'Miễn phí cho học viên có **gói thành viên Ellso**. Số chỗ có hạn.']],
                ['type' => 'bullets', 'data' => ['title' => 'Bạn sẽ học được', 'items' => ['Nhận ra lỗi lập luận thường gặp', 'Đặt câu hỏi đúng trước khi quyết định']]],
            ],
        ]);
        foreach ([12, 19] as $day) {
            TrainingSession::create(['training_course_id' => $critical->id, 'training_location_id' => $cafeA->id, 'starts_at' => $at($day, '19:00'), 'ends_at' => $at($day, '21:00')]);
        }

        $this->command?->info('Đã tạo 2 chương trình huấn luyện mẫu + 2 địa điểm.');
    }

    /** Banner 1280 × 720 đơn giản (màu Ellso). */
    private function banner(string $path, array $accent): string
    {
        $img = imagecreatetruecolor(1280, 720);
        for ($y = 0; $y < 720; $y++) {
            imageline($img, 0, $y, 1280, $y, imagecolorallocate($img, 4, (int) (120 + $y * 0.08), (int) (87 + $y * 0.04)));
        }
        imagefilledellipse($img, 1100, 140, 520, 520, imagecolorallocate($img, 16, 185, 129));
        imagefilledellipse($img, 1130, 560, 240, 240, imagecolorallocate($img, ...$accent));
        ob_start();
        imagepng($img);
        MediaStorage::disk()->put($path, ob_get_clean());

        return $path;
    }
}
