<?php

namespace Database\Seeders;

use App\Enums\CoinSource;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\CoinLedger;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Dữ liệu demo lịch sử xu (chỉ local): mỗi học viên ~45 lần cộng / trừ xu rải trong 4 tháng gần đây.
 * php artisan db:seed --class=DemoCoinSeeder
 */
class DemoCoinSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction() && ! config('ellso.allow_demo_seed')) {
            throw new RuntimeException('Không chạy dữ liệu demo trên production (đặt ALLOW_DEMO_SEED=true để cho phép tạm thời).');
        }

        $ledger = app(CoinLedger::class);
        $samples = [
            [CoinSource::Referral, 100, 'Giới thiệu bạn bè đăng ký thành công'],
            [CoinSource::Learning, 20, 'Hoàn thành bài học'],
            [CoinSource::Learning, 50, 'Hoàn thành chương'],
            [CoinSource::Learning, 200, 'Hoàn thành khoá học'],
            [CoinSource::Spend, -80, 'Đổi xu lấy tài liệu'],
            [CoinSource::Admin, 30, 'Quà tặng từ Ellso'],
        ];

        User::query()->where('role', UserRole::Student)->each(function (User $user) use ($ledger, $samples) {
            $user->coinTransactions()->delete();
            $user->forceFill(['coin_balance' => 0])->save();
            $when = now()->subMonths(4);
            for ($i = 0; $i < 45; $i++) {
                [$source, $amount, $text] = $samples[array_rand($samples)];
                if ($amount < 0 && $user->coin_balance + $amount < 0) {
                    [$source, $amount, $text] = $samples[1];
                }
                $when = $when->copy()->addHours(random_int(10, 60));
                $tx = $ledger->record($user, $amount, $source, $text);
                $tx->forceFill(['created_at' => $when, 'updated_at' => $when])->save();
            }
        });
    }
}
