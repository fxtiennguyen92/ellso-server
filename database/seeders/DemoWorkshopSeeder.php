<?php

namespace Database\Seeders;

use App\Enums\RegistrationSource;
use App\Enums\TrainingCourseStatus;
use App\Enums\UserRole;
use App\Models\User;
use App\Models\Workshop;
use App\Models\WorkshopSession;
use App\Support\MediaStorage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * 2 workshop online MẪU:
 *  - "Giao tiếp tự tin khi thuyết trình": miễn phí với gói thành viên, tối đa 30 người / buổi, 3 buổi (khác ngày, khác giờ).
 *  - "Viết email công việc chuyên nghiệp": 150.000 ₫ / buổi, không giới hạn, 2 buổi.
 * Học viên demo (@demo.ellso.test) đang có gói được thêm vào buổi đầu của workshop miễn phí.
 * Chạy tay:  php artisan db:seed --class=DemoWorkshopSeeder   (chạy lại sẽ thay workshop mẫu cùng tên)
 */
class DemoWorkshopSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction() && ! config('ellso.allow_demo_seed')) {
            throw new RuntimeException('Không chạy dữ liệu demo trên production (đặt ALLOW_DEMO_SEED=true để cho phép tạm thời).');
        }

        $titles = ['Giao tiếp tự tin khi thuyết trình', 'Viết email công việc chuyên nghiệp'];
        Workshop::whereIn('title', $titles)->get()->each->forceDelete();
        $trainerId = User::where('role', UserRole::Instructor)->value('id');
        $at = fn (int $days, string $time) => CarbonImmutable::parse(now('Asia/Ho_Chi_Minh')->addDays($days)->toDateString().' '.$time, 'Asia/Ho_Chi_Minh')->utc();

        $speaking = Workshop::create([
            'title' => $titles[0],
            'summary' => 'Workshop online 2 giờ: cấu trúc bài nói 3 phần, giọng nói và ánh mắt qua camera.',
            'banner_path' => $this->banner('workshops/banners/demo-thuyet-trinh.png', [236, 72, 153]),
            'price' => 0,
            'capacity' => 30,
            'trainer_id' => $trainerId,
            'status' => TrainingCourseStatus::Published,
            'content' => [
                ['type' => 'paragraph', 'data' => ['text' => 'Học trực tuyến qua Zoom, có thực hành nói 1 phút và nhận góp ý ngay. **Miễn phí** với học viên có gói thành viên.']],
                ['type' => 'bullets', 'data' => ['title' => 'Bạn sẽ học được', 'items' => ['Mở đầu thu hút trong 30 giây', 'Giữ nhịp và kết thúc gọn', 'Trả lời câu hỏi khó']]],
            ],
        ]);
        $first = WorkshopSession::create(['workshop_id' => $speaking->id, 'starts_at' => $at(5, '19:30'), 'ends_at' => $at(5, '21:30'), 'meeting_url' => 'https://zoom.us/j/1234567890']);
        WorkshopSession::create(['workshop_id' => $speaking->id, 'starts_at' => $at(12, '09:00'), 'ends_at' => $at(12, '11:00'), 'meeting_url' => 'https://zoom.us/j/1234567891']);
        WorkshopSession::create(['workshop_id' => $speaking->id, 'starts_at' => $at(19, '19:30'), 'ends_at' => $at(19, '21:30'), 'note' => 'Link họp sẽ gửi qua email trước 1 ngày']);

        $email = Workshop::create([
            'title' => $titles[1],
            'summary' => 'Viết email rõ ràng, lịch sự và được trả lời nhanh — có mẫu dùng ngay.',
            'banner_path' => $this->banner('workshops/banners/demo-email.png', [59, 130, 246]),
            'price' => 150_000,
            'capacity' => null,
            'trainer_id' => $trainerId,
            'status' => TrainingCourseStatus::Published,
            'content' => [
                ['type' => 'paragraph', 'data' => ['text' => 'Học qua Google Meet. Nhận bộ 10 mẫu email sau buổi học.']],
            ],
        ]);
        WorkshopSession::create(['workshop_id' => $email->id, 'starts_at' => $at(8, '20:00'), 'ends_at' => $at(8, '21:30'), 'meeting_url' => 'https://meet.google.com/abc-defg-hij']);
        WorkshopSession::create(['workshop_id' => $email->id, 'starts_at' => $at(15, '20:00'), 'ends_at' => $at(15, '21:30'), 'meeting_url' => 'https://meet.google.com/abc-defg-hij']);

        $members = User::query()
            ->where('role', UserRole::Student)
            ->where('email', 'like', '%@demo.ellso.test')
            ->whereHas('memberships', fn ($q) => $q->active())
            ->limit(8)
            ->get();
        foreach ($members as $member) {
            $first->registrations()->create(['user_id' => $member->id, 'source' => RegistrationSource::App]);
        }

        $this->command?->info('Đã tạo 2 workshop mẫu ('.$members->count().' người đăng ký demo).');
    }

    /** Banner 1280 × 720 đơn giản (màu Ellso). */
    private function banner(string $path, array $accent): string
    {
        $img = imagecreatetruecolor(1280, 720);
        for ($y = 0; $y < 720; $y++) {
            imageline($img, 0, $y, 1280, $y, imagecolorallocate($img, 15, (int) (60 + $y * 0.05), (int) (90 + $y * 0.06)));
        }
        imagefilledellipse($img, 1080, 160, 480, 480, imagecolorallocate($img, ...$accent));
        imagefilledellipse($img, 200, 600, 300, 300, imagecolorallocate($img, 16, 185, 129));
        ob_start();
        imagepng($img);
        MediaStorage::disk()->put($path, ob_get_clean());

        return $path;
    }
}
