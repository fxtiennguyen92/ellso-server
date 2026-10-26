<?php

namespace App\Models;

use App\Support\AbilityClassifications;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** Phân loại đánh giá (VD: Khả năng tiếp thu), gồm nhiều mức độ. */
class PlacementDimension extends Model
{
    protected $fillable = ['key', 'placement_test_id', 'name', 'description', 'sort_order'];

    protected static function booted(): void
    {
        // Mã ổn định (giữ khi nhân bản bài) để nối với phân loại "Năng lực" của lộ trình
        static::creating(function (self $model) {
            $model->key ??= (string) Str::uuid();
        });
        static::saved(fn () => AbilityClassifications::syncSoon());
        static::deleted(fn () => AbilityClassifications::syncSoon());
    }

    public function test(): BelongsTo
    {
        return $this->belongsTo(PlacementTest::class, 'placement_test_id');
    }

    /** Mức độ, từ cao xuống thấp theo điểm sàn. */
    public function levels(): HasMany
    {
        return $this->hasMany(PlacementLevel::class)->orderByDesc('min_score')->orderBy('id');
    }
}
