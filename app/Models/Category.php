<?php

namespace App\Models;

use App\Models\Concerns\HasUniqueSlug;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasUniqueSlug;

    protected string $slugSource = 'name';

    protected $fillable = ['parent_id', 'name', 'slug', 'description', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** Khoá học thuộc danh mục này (một khoá có thể thuộc nhiều danh mục). */
    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class);
    }
}
