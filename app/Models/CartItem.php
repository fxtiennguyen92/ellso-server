<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Một món trong giỏ hàng: khoá học hoặc tài liệu (sách) có bán. Xem Api\CartController. */
class CartItem extends Model
{
    public const TYPE_COURSE = 'course';

    public const TYPE_DOCUMENT = 'document';

    protected $fillable = ['user_id', 'item_type', 'item_id'];

    protected function casts(): array
    {
        return ['item_id' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
