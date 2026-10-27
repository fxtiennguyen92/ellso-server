<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Sách học viên đã mua (source: admin cấp | purchase mua online — làm cùng đợt thanh toán). */
class DocumentPurchase extends Model
{
    protected $fillable = ['user_id', 'document_id', 'price', 'source', 'granted_by_id', 'note', 'purchased_at'];

    protected function casts(): array
    {
        return ['price' => 'integer', 'purchased_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class)->withTrashed();
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_id');
    }
}
