<?php

namespace App\Services;

use App\Enums\CoinSource;
use App\Models\CoinTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Cộng / trừ xu: cập nhật users.coin_balance và ghi một dòng lịch sử trong cùng transaction. */
class CoinLedger
{
    public function record(User $user, int $amount, CoinSource $source, string $description, ?array $meta = null, ?User $by = null): CoinTransaction
    {
        if ($amount === 0) {
            throw new InvalidArgumentException('Số xu phải khác 0.');
        }

        return DB::transaction(function () use ($user, $amount, $source, $description, $meta, $by) {
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $balance = (int) $locked->coin_balance + $amount;
            if ($balance < 0) {
                throw new InvalidArgumentException('Không đủ xu.');
            }

            $locked->forceFill(['coin_balance' => $balance])->save();
            $user->coin_balance = $balance;

            return CoinTransaction::create([
                'user_id' => $locked->getKey(),
                'amount' => $amount,
                'balance_after' => $balance,
                'source' => $source,
                'description' => $description,
                'meta' => $meta,
                'created_by_id' => $by?->getKey(),
            ]);
        });
    }
}
