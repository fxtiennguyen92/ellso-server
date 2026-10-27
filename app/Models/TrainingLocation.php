<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Địa điểm trong sổ địa chỉ huấn luyện — lưu một lần, chọn lại theo nhãn cho các ngày sau. */
class TrainingLocation extends Model
{
    use SoftDeletes;

    protected $fillable = ['label', 'address', 'map_url', 'note'];

    public function sessions(): HasMany
    {
        return $this->hasMany(TrainingSession::class);
    }

    /** "Cafe A – Quận 1 · 12 Nguyễn Huệ, Q.1" cho ô chọn. */
    public function optionLabel(): string
    {
        return $this->label . ' · ' . $this->address;
    }
}
