<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromoCodeRedemption extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'promo_code_id',
        'user_id',
        'order_id',
        'order_amount',
        'discount_amount',
    ];

    protected function casts(): array
    {
        return [
            'order_amount' => 'integer',
            'discount_amount' => 'integer',
        ];
    }

    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
