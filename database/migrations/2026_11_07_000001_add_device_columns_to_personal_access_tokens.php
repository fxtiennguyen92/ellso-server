<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Thông tin thiết bị của mỗi phiên đăng nhập (token) — app › Mật khẩu & Bảo mật › Thiết bị đăng nhập. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('platform', 20)->nullable()->after('abilities');
            $table->string('os_version', 50)->nullable()->after('platform');
            $table->string('app_version', 50)->nullable()->after('os_version');
            $table->string('device_id', 100)->nullable()->after('app_version')->index();
            $table->string('ip_address', 45)->nullable()->after('device_id');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropIndex(['device_id']);
            $table->dropColumn(['platform', 'os_version', 'app_version', 'device_id', 'ip_address']);
        });
    }
};
