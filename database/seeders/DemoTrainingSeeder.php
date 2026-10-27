<?php

namespace Database\Seeders;

use App\Enums\TrainingCourseStatus;
use App\Enums\UserRole;
use App\Models\TrainingCourse;
use App\Models\TrainingLocation;
use App\Models\TrainingSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Khoá huấn luyện MẪU: "Kỹ năng quản lý đội nhóm" 200.000 ₫/người,
 * ngày 1 ở Cafe A (tối đa 10), ngày 2 ở Cafe B (tối đa 20).
 * Chỉ chạy tay ở local:  php artisan db:seed --class=DemoTrainingSeeder
 */
class DemoTrainingSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Không chạy dữ liệu demo trên production.');
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

        TrainingCourse::where('title', 'Kỹ năng quản lý đội nhóm')->get()->each->forceDelete();

        $course = TrainingCourse::create([
            'title' => 'Kỹ năng quản lý đội nhóm',
            'summary' => 'Buổi huấn luyện 3 giờ giúp trưởng nhóm mới giao việc, theo dõi và phản hồi hiệu quả.',
            'banner_path' => $this->banner(),
            'price' => 200_000,
            'trainer_id' => User::where('role', UserRole::Instructor)->value('id'),
            'status' => TrainingCourseStatus::Published,
            'content' => [
                ['type' => 'paragraph', 'data' => ['text' => 'Dành cho người vừa lên vị trí quản lý hoặc chuẩn bị dẫn dắt nhóm **3–10 người**. Học qua tình huống thực tế, thực hành theo cặp.']],
                ['type' => 'bullets', 'data' => ['title' => 'Bạn sẽ học được', 'items' => ['Giao việc rõ ràng với khung 5W1H', 'Họp 1:1 hiệu quả trong 15 phút', 'Phản hồi để nhân viên tiến bộ, không mất động lực']]],
                ['type' => 'bullets', 'data' => ['title' => 'Phù hợp với', 'items' => ['Trưởng nhóm, quản lý cấp 1', 'Nhân viên sắp được đề bạt']]],
                ['type' => 'callout', 'data' => ['tone' => 'info', 'title' => 'Mang theo', 'text' => 'Sổ tay và một tình huống khó bạn đang gặp với nhóm.']],
            ],
        ]);

        $at = fn (int $days, string $time) => CarbonImmutable::parse(now('Asia/Ho_Chi_Minh')->addDays($days)->toDateString() . ' ' . $time, 'Asia/Ho_Chi_Minh')->utc();
        TrainingSession::create(['training_course_id' => $course->id, 'training_location_id' => $cafeA->id, 'starts_at' => $at(20, '18:00'), 'ends_at' => $at(20, '21:00'), 'capacity' => 10]);
        TrainingSession::create(['training_course_id' => $course->id, 'training_location_id' => $cafeB->id, 'starts_at' => $at(25, '08:30'), 'ends_at' => $at(25, '11:30'), 'capacity' => 20, 'note' => 'Có xuất ăn sáng nhẹ']);
        TrainingSession::create(['training_course_id' => $course->id, 'training_location_id' => $cafeA->id, 'starts_at' => $at(-10, '18:00'), 'ends_at' => $at(-10, '21:00'), 'capacity' => 10]);

        $this->command?->info('Đã tạo khoá huấn luyện mẫu + 2 địa điểm.');
    }

    /** Banner 1280 × 720 đơn giản (màu Ellso). */
    private function banner(): string
    {
        $path = 'trainings/banners/demo-quan-ly.png';
        $img = imagecreatetruecolor(1280, 720);
        for ($y = 0; $y < 720; $y++) {
            imageline($img, 0, $y, 1280, $y, imagecolorallocate($img, 4, (int) (120 + $y * 0.08), (int) (87 + $y * 0.04)));
        }
        imagefilledellipse($img, 1100, 140, 520, 520, imagecolorallocate($img, 16, 185, 129));
        imagefilledellipse($img, 1130, 560, 240, 240, imagecolorallocate($img, 245, 158, 11));
        ob_start();
        imagepng($img);
        Storage::disk('public')->put($path, ob_get_clean());

        return $path;
    }
}
