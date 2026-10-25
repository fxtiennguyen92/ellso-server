<?php

namespace Database\Seeders;

use App\Enums\DiscountType;
use App\Enums\UserRole;
use App\Models\PromoCode;
use App\Models\PromoCodeRedemption;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Dữ liệu DEMO cho màn Thống kê › Mã khuyến mãi khi chưa có đơn hàng thật.
 * Chỉ chạy tay ở local:  php artisan db:seed --class=DemoPromoCodeSeeder
 * Mã demo có tiền tố DEMO, học viên demo có email @demo.ellso.test.
 */
class DemoPromoCodeSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Không chạy dữ liệu demo trên production.');
        }

        $students = collect(range(1, 40))->map(fn (int $i) => User::firstOrCreate(
            ['email' => "hocvien{$i}@demo.ellso.test"],
            [
                'name' => "Học viên demo {$i}",
                'password' => 'password',
                'role' => UserRole::Student,
                'is_active' => true,
                'password_changed_at' => now(),
            ],
        ));

        $codes = [
            ['DEMO5PCT', 'Demo – giảm 5% tối đa 500k', DiscountType::Percent, 5, 500_000],
            ['DEMO200K', 'Demo – giảm 200.000 ₫', DiscountType::Fixed, 200_000, null],
            ['DEMOVIP10', 'Demo – VIP giảm 10%', DiscountType::Percent, 10, 1_000_000],
        ];

        $prices = [990_000, 1_490_000, 2_490_000, 4_990_000];

        foreach ($codes as [$code, $name, $type, $value, $max]) {
            $promo = PromoCode::updateOrCreate(['code' => $code], [
                'name' => $name,
                'discount_type' => $type,
                'discount_value' => $value,
                'max_discount' => $max,
                'starts_at' => now()->subDays(120),
                'ends_at' => now()->addDays(30),
                'per_user_limit' => null,
            ]);

            $promo->redemptions()->delete();
            $count = random_int(60, 140);

            for ($i = 0; $i < $count; $i++) {
                $amount = $prices[array_rand($prices)];
                $discount = $type === DiscountType::Percent
                    ? min((int) floor($amount * $value / 100), $max ?? PHP_INT_MAX)
                    : min($value, $amount);

                PromoCodeRedemption::create([
                    'promo_code_id' => $promo->id,
                    'user_id' => $students->random()->id,
                    'order_amount' => $amount,
                    'discount_amount' => $discount,
                ])->forceFill(['created_at' => now()->subMinutes(random_int(0, 90 * 24 * 60))])->save();
            }

            $promo->forceFill(['used_count' => $count])->save();
        }
    }
}
