<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Số xu học viên đang có (hiện ở màn Hồ sơ của app). Cách cộng / trừ xu sẽ làm sau. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('coin_balance')->default(0)->after('referral_code');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('coin_balance');
        });
    }
};
