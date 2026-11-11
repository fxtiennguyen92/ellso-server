<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Lịch sử xu: mỗi lần cộng / trừ xu một dòng (users.coin_balance = số dư hiện tại). Ghi qua App\Services\CoinLedger. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coin_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('amount'); // + cộng, − trừ
            $table->unsignedInteger('balance_after');
            $table->string('source', 30);
            $table->string('description');
            $table->json('meta')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coin_transactions');
    }
};
