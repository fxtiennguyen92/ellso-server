<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\AppSession;
use App\Models\StudentMembership;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Dữ liệu DEMO cho Thống kê › Hoạt động học viên (lượt mở app + gói học viên).
 * Chỉ chạy tay ở local:  php artisan db:seed --class=DemoStudentActivitySeeder
 * Dùng học viên demo @demo.ellso.test (tạo nếu chưa có); chạy lại sẽ thay dữ liệu demo cũ.
 */
class DemoStudentActivitySeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction() && ! config('ellso.allow_demo_seed')) {
            throw new RuntimeException('Không chạy dữ liệu demo trên production (đặt ALLOW_DEMO_SEED=true để cho phép tạm thời).');
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

        // Ngày đăng ký rải trong 120 ngày gần nhất (cho đường "học viên mới")
        $students->each(fn (User $u) => $u->forceFill(['created_at' => now()->subMinutes(random_int(0, 120 * 24 * 60))])->saveQuietly());

        $ids = $students->pluck('id');
        AppSession::whereIn('user_id', $ids)->delete();
        StudentMembership::whereIn('user_id', $ids)->delete();

        // Giờ cao điểm: tối 20–22h, trưa 12h, sáng 7h; cuối tuần dùng ít hơn
        $hourWeights = [1, 0, 0, 0, 0, 1, 3, 6, 4, 3, 3, 4, 7, 5, 3, 3, 3, 4, 5, 7, 10, 11, 8, 4];
        $weekdayWeights = [1 => 10, 2 => 9, 3 => 9, 4 => 8, 5 => 7, 6 => 4, 7 => 5];
        $platforms = ['ios' => 50, 'android' => 40, 'web' => 10];
        $devices = ['ios' => 'iPhone 15', 'android' => 'Samsung Galaxy S24', 'web' => 'Chrome'];

        $rows = [];
        foreach ($students as $student) {
            $platform = $this->weighted($platforms);
            $activity = random_int(2, 10) / 10; // mức chăm chỉ của từng người

            for ($daysAgo = 120; $daysAgo >= 0; $daysAgo--) {
                $day = Carbon::now('Asia/Ho_Chi_Minh')->subDays($daysAgo);
                if ($day->lt($student->created_at)) {
                    continue; // chưa đăng ký thì chưa mở app
                }
                $chance = $weekdayWeights[$day->isoWeekday()] / 10 * $activity;

                $opens = random_int(1, 100) <= $chance * 100 ? random_int(1, 3) : 0;
                for ($k = 0; $k < $opens; $k++) {
                    $start = $day->copy()->setTime($this->weighted($hourWeights), random_int(0, 59))->utc();
                    if ($start->isFuture()) {
                        continue;
                    }

                    $rows[] = [
                        'user_id' => $student->id,
                        'device_id' => 'demo-' . $student->id,
                        'device_name' => $devices[$platform],
                        'platform' => $platform,
                        'started_at' => $start,
                        'last_seen_at' => $start->copy()->addSeconds(random_int(60, 40 * 60)),
                        'ping_count' => random_int(1, 40),
                    ];
                }
            }
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            AppSession::insert($chunk);
        }

        // ~35% đang có gói, ~10% từng mua nhưng đã hết hạn
        foreach ($students as $i => $student) {
            $roll = $i % 20;
            if ($roll < 7) {
                StudentMembership::create(['user_id' => $student->id, 'starts_at' => now()->subMonths(random_int(0, 10)), 'ends_at' => now()->addMonths(random_int(1, 12)), 'price' => 2_990_000, 'source' => 'purchase']);
            } elseif ($roll < 9) {
                StudentMembership::create(['user_id' => $student->id, 'starts_at' => now()->subMonths(14), 'ends_at' => now()->subMonths(2), 'price' => 2_990_000, 'source' => 'purchase']);
            }
        }
    }

    /** @param  array<int|string, int>  $weights */
    private function weighted(array $weights): int | string
    {
        $pick = random_int(1, array_sum($weights));
        foreach ($weights as $key => $weight) {
            if (($pick -= $weight) <= 0) {
                return $key;
            }
        }

        return array_key_first($weights);
    }
}
