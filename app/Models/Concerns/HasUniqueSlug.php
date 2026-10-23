<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Tự sinh slug duy nhất từ cột tên khi slug để trống.
 * Model dùng trait này khai báo: protected string $slugSource = 'name';
 */
trait HasUniqueSlug
{
    protected static function bootHasUniqueSlug(): void
    {
        static::saving(function ($model) {
            if (blank($model->slug)) {
                $model->slug = $model->makeUniqueSlug($model->{$model->slugSource ?? 'name'});
            }
        });
    }

    public function makeUniqueSlug(?string $value): string
    {
        $base = Str::slug((string) $value) ?: Str::lower(Str::random(8));
        $slug = $base;
        $i = 2;

        $query = fn (string $s) => static::query()
            ->when(method_exists($this, 'bootSoftDeletes'), fn ($q) => $q->withTrashed())
            ->where('slug', $s)
            ->when($this->exists, fn ($q) => $q->whereKeyNot($this->getKey()))
            ->exists();

        while ($query($slug)) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
