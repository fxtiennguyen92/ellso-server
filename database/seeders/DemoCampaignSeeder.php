<?php

namespace Database\Seeders;

use App\Enums\DiscountType;
use App\Models\Course;
use App\Models\MarketingCampaign;
use App\Models\PromoCode;
use App\Support\MediaStorage;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * 2 chương trình khuyến mãi MẪU (banner trượt trên Home + trang chi tiết):
 *  - "Ưu đãi chào tháng mới": mã ELLSO giảm 20% (tối đa 300.000 ₫), nút mở khoá học.
 *  - "Học nhóm tiết kiệm hơn": mã NHOM3 giảm 150.000 ₫ cho đơn từ 1.000.000 ₫, nút mở link.
 * Chạy tay:  php artisan db:seed --class=DemoCampaignSeeder   (chạy lại sẽ thay chiến dịch mẫu cùng tên)
 */
class DemoCampaignSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction() && ! config('ellso.allow_demo_seed')) {
            throw new RuntimeException('Không chạy dữ liệu demo trên production (đặt ALLOW_DEMO_SEED=true để cho phép tạm thời).');
        }

        $welcome = PromoCode::updateOrCreate(['code' => 'ELLSO'], [
            'name' => 'Ưu đãi chào tháng mới',
            'description' => 'Giảm 20% mọi khoá học, tối đa 300.000 ₫',
            'discount_type' => DiscountType::Percent,
            'discount_value' => 20,
            'max_discount' => 300_000,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(30),
            'per_user_limit' => 1,
            'is_active' => true,
        ]);
        $group = PromoCode::updateOrCreate(['code' => 'NHOM3'], [
            'name' => 'Học nhóm tiết kiệm hơn',
            'description' => 'Giảm 150.000 ₫ cho đơn từ 1.000.000 ₫',
            'discount_type' => DiscountType::Fixed,
            'discount_value' => 150_000,
            'min_order_amount' => 1_000_000,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(45),
            'is_active' => true,
        ]);

        $titles = ['Ưu đãi chào tháng mới', 'Học nhóm tiết kiệm hơn'];
        MarketingCampaign::withTrashed()->whereIn('title', $titles)->get()->each->forceDelete();

        MarketingCampaign::create([
            'title' => $titles[0],
            'summary' => 'Giảm 20% mọi khoá học E-learning trong 30 ngày.',
            'banner_path' => $this->banner('campaigns/demo-thang-moi.png', [16, 185, 129], [245, 158, 11]),
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(30),
            'is_active' => true,
            'sort_order' => 0,
            'content' => [
                ['type' => 'paragraph', 'data' => ['text' => 'Bắt đầu tháng mới bằng một kỹ năng mới! Nhập mã bên dưới khi thanh toán để được giảm 20% cho bất kỳ khoá học nào.']],
                ['type' => 'promo_code', 'data' => ['promo_code_id' => $welcome->id, 'note' => 'Mỗi tài khoản dùng 1 lần']],
                ['type' => 'steps', 'data' => ['title' => 'Cách áp dụng', 'items' => ['Thêm khoá học vào giỏ hàng', 'Bấm "Nhập mã giảm giá" và nhập ELLSO', 'Thanh toán như bình thường']]],
                ['type' => 'bullets', 'data' => ['title' => 'Điều kiện áp dụng', 'items' => ['Áp dụng cho tất cả khoá học E-learning', 'Giảm tối đa 300.000 ₫ / đơn', 'Không cộng dồn với mã khác']]],
            ],
            'cta_type' => MarketingCampaign::CTA_COURSE,
            'cta_label' => 'Xem khoá học',
            'cta_course_id' => Course::query()->where('status', 'published')->value('id'),
        ]);

        MarketingCampaign::create([
            'title' => $titles[1],
            'summary' => 'Rủ bạn cùng học — giảm ngay 150.000 ₫ cho đơn từ 1 triệu.',
            'banner_path' => $this->banner('campaigns/demo-hoc-nhom.png', [99, 102, 241], [236, 72, 153]),
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(45),
            'is_active' => true,
            'sort_order' => 1,
            'content' => [
                ['type' => 'paragraph', 'data' => ['text' => 'Học cùng bạn bè giúp bạn duy trì động lực tốt hơn. Gom các khoá học vào một đơn từ 1.000.000 ₫ để nhận ưu đãi.']],
                ['type' => 'promo_code', 'data' => ['promo_code_id' => $group->id]],
                ['type' => 'callout', 'data' => ['tone' => 'warning', 'title' => 'Lưu ý', 'text' => 'Ưu đãi kết thúc sau 45 ngày hoặc khi hết lượt.']],
            ],
            'cta_type' => MarketingCampaign::CTA_URL,
            'cta_label' => 'Tìm hiểu thêm',
            'cta_url' => 'https://ellso.vn',
        ]);
    }

    /** Banner 1280×720 vẽ bằng GD. */
    private function banner(string $path, array $main, array $accent): string
    {
        $img = imagecreatetruecolor(MarketingCampaign::BANNER_WIDTH, MarketingCampaign::BANNER_HEIGHT);
        for ($y = 0; $y < 720; $y++) {
            imageline($img, 0, $y, 1280, $y, imagecolorallocate($img, (int) ($main[0] * 0.35), (int) ($main[1] * 0.35 + $y * 0.03), (int) ($main[2] * 0.35 + $y * 0.02)));
        }
        imagefilledellipse($img, 1060, 200, 620, 620, imagecolorallocate($img, ...$main));
        imagefilledellipse($img, 1180, 600, 300, 300, imagecolorallocate($img, ...$accent));
        imagefilledellipse($img, 180, 640, 180, 180, imagecolorallocatealpha($img, 255, 255, 255, 100));
        ob_start();
        imagepng($img);
        MediaStorage::disk()->put($path, ob_get_clean());

        return $path;
    }
}
