<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Chủ đề tài liệu (Tư duy, Lãnh đạo…) — chỉ dùng để lọc trên app. */
class DocumentTopic extends Model
{
    protected $fillable = ['name', 'sort_order'];

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}
