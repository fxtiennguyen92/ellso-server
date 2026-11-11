<?php

namespace App\Models;

use App\Enums\CoinSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Một lần cộng / trừ xu. Chỉ tạo qua App\Services\CoinLedger (giữ đúng users.coin_balance). */
class CoinTransaction extends Model
{
    protected $fillable = ['user_id', 'amount', 'balance_after', 'source', 'description', 'meta', 'created_by_id'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'balance_after' => 'integer',
            'source' => CoinSource::class,
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
