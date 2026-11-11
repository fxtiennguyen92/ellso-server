<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Câu chào dưới tên học viên ở Trang chủ app — sửa ở Hệ thống › Cấu hình hệ thống. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('system_settings')->insertOrIgnore([
            'key' => 'home_tagline',
            'value' => 'Hôm nay học gì cùng Ellso?',
            'type' => 'string',
            'group' => 'app',
            'label' => 'Câu chào ở Trang chủ app',
            'unit' => null,
            'description' => 'Dòng chữ cạnh ngày hôm nay trên Trang chủ app. Để trống thì app dùng câu mặc định "Hôm nay học gì cùng Ellso?".',
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Cache::forget('system_settings');
    }

    public function down(): void
    {
        DB::table('system_settings')->where('key', 'home_tagline')->delete();
        Cache::forget('system_settings');
    }
};
