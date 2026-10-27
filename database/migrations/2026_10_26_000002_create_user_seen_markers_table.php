<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mốc "đã xem đến lúc nào" của từng người dùng CMS cho một danh sách (VD: placement_attempts).
 * Dùng để hiện chấm đỏ / số đỏ cho mục mới mà người đó chưa xem. Dùng chung cho các danh sách sau này.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_seen_markers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 60);
            $table->timestamp('seen_at');
            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_seen_markers');
    }
};
