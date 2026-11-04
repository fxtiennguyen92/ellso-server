<?php

namespace Database\Seeders;

use App\Models\PartnerEvent;
use App\Support\MediaStorage;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Sự kiện ngoài lề MẪU: 3 tin đang hiển thị + 1 tin sắp hiển thị.
 * Chạy tay:  php artisan db:seed --class=DemoPartnerEventSeeder
 * Chạy lại sẽ thay các tin mẫu cũ (cùng tiêu đề).
 */
class DemoPartnerEventSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction() && ! config('ellso.allow_demo_seed')) {
            throw new RuntimeException('Không chạy dữ liệu demo trên production (đặt ALLOW_DEMO_SEED=true để cho phép tạm thời).');
        }

        $events = [
            [
                'title' => 'Hội thảo "AI trong marketing 2027"',
                'time_text' => '18:30 – 21:00, thứ Năm 20/11/2026',
                'location' => 'GEM Center, 8 Nguyễn Bỉnh Khiêm, Quận 1, TP.HCM',
                'description' => "Hội thảo do đối tác TechHub tổ chức, chia sẻ cách các doanh nghiệp Việt đang dùng AI để viết nội dung, chạy quảng cáo và đo hiệu quả.\nPhù hợp với học viên các khoá Marketing và Tạo Web bằng AI.\nVào cửa tự do, đăng ký tại quầy lễ tân.",
                'color' => [16, 185, 129],
                'starts' => -2,
                'ends' => 15,
            ],
            [
                'title' => 'Ngày hội việc làm công nghệ',
                'time_text' => 'Cả ngày Chủ nhật 30/11/2026',
                'location' => 'Nhà văn hoá Thanh niên, 4 Phạm Ngọc Thạch, Quận 1',
                'description' => "Hơn 40 công ty tuyển dụng các vị trí lập trình, dữ liệu, thiết kế và marketing số.\nMang theo CV in sẵn; có khu chụp ảnh hồ sơ miễn phí.",
                'color' => [99, 102, 241],
                'starts' => -1,
                'ends' => 25,
            ],
            [
                'title' => 'Workshop online: Viết CV bằng tiếng Anh',
                'time_text' => '20:00 – 21:30, thứ Ba 18/11/2026',
                'location' => 'Online qua Zoom (đối tác gửi link sau khi đăng ký)',
                'description' => 'Chuyên gia tuyển dụng từ đối tác HR Partners hướng dẫn viết CV tiếng Anh ngắn gọn, đúng chuẩn nhà tuyển dụng quốc tế.',
                'color' => [217, 119, 6],
                'starts' => 0,
                'ends' => 12,
            ],
            [
                'title' => 'Triển lãm sách & tri thức mùa đông',
                'time_text' => '05/12 – 08/12/2026',
                'location' => 'Đường sách Nguyễn Văn Bình, Quận 1',
                'description' => 'Giảm giá sách kỹ năng, kinh doanh; giao lưu với tác giả vào tối thứ Bảy.',
                'color' => [190, 18, 60],
                'starts' => 10,
                'ends' => 40,
            ],
        ];

        PartnerEvent::query()->whereIn('title', array_column($events, 'title'))->get()->each->delete();

        $order = (int) PartnerEvent::query()->max('sort_order');
        foreach ($events as $i => $e) {
            PartnerEvent::create([
                'title' => $e['title'],
                'image_path' => $this->image("partner-events/demo-{$i}.png", $e['color']),
                'time_text' => $e['time_text'],
                'location' => $e['location'],
                'description' => $e['description'],
                'starts_at' => now()->addDays($e['starts'])->startOfHour(),
                'ends_at' => now()->addDays($e['ends'])->endOfDay(),
                'is_active' => true,
                'sort_order' => ++$order,
            ]);
        }

        $this->command?->info('Đã tạo ' . count($events) . ' sự kiện ngoài lề mẫu.');
    }

    /** Ảnh 1280 × 720 đơn giản theo màu. */
    private function image(string $path, array $rgb): string
    {
        $img = imagecreatetruecolor(PartnerEvent::IMAGE_WIDTH, PartnerEvent::IMAGE_HEIGHT);
        [$r, $g, $b] = $rgb;
        for ($y = 0; $y < PartnerEvent::IMAGE_HEIGHT; $y++) {
            $k = $y / PartnerEvent::IMAGE_HEIGHT;
            imageline($img, 0, $y, PartnerEvent::IMAGE_WIDTH, $y, imagecolorallocate($img, (int) ($r * (1 - 0.4 * $k)), (int) ($g * (1 - 0.4 * $k)), (int) ($b * (1 - 0.4 * $k))));
        }
        imagefilledellipse($img, 1080, 160, 460, 460, imagecolorallocatealpha($img, 255, 255, 255, 100));
        imagefilledellipse($img, 200, 620, 300, 300, imagecolorallocatealpha($img, 255, 255, 255, 110));
        ob_start();
        imagepng($img);
        MediaStorage::disk()->put($path, ob_get_clean());

        return $path;
    }
}
