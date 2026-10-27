<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Classification;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Tài khoản admin đầu tiên. Đặt ADMIN_EMAIL / ADMIN_PASSWORD trong .env trước khi seed production.
        User::updateOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@ellso.edu.vn')],
            [
                'name' => 'Quản trị viên',
                'password' => env('ADMIN_PASSWORD', 'password'),
                'role' => UserRole::Admin,
                'is_active' => true,
                'email_verified_at' => now(),
                'password_changed_at' => now(),
            ],
        );

        // Phân loại dùng chung
        $shared = [
            ['Nghề nghiệp', 'Bạn đang làm nghề gì?', ['Marketing', 'IT', 'Giáo viên', 'Sinh viên']],
            ['Mục tiêu học', 'Mục tiêu của bạn với khóa học này?', ['Hiểu', 'Làm được', 'Chuyên sâu']],
        ];

        foreach ($shared as $i => [$name, $question, $options]) {
            $classification = Classification::firstOrCreate(
                ['name' => $name, 'course_id' => null],
                ['question' => $question, 'sort_order' => $i],
            );

            foreach ($options as $j => $option) {
                $classification->options()->firstOrCreate(['name' => $option], ['sort_order' => $j]);
            }
        }

        if (app()->isLocal()) {
            $this->call(DemoCourseSeeder::class);
        }
    }
}
