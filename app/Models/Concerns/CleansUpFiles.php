<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * Xoá file trên disk "public" khi không còn dùng:
 *  - Sửa bản ghi: ảnh bị bỏ (bấm X rồi Lưu) hoặc thay ảnh khác → xoá file cũ.
 *  - Xoá bản ghi → xoá file của nó. Model có thùng rác (khôi phục được) thì chỉ xoá file khi xoá vĩnh viễn;
 *    model không có thùng rác trên CMS đặt $purgeFilesOnSoftDelete = true để xoá ngay.
 * File còn được bản ghi khác dùng (VD: bản nhân bản dùng chung banner) thì giữ lại.
 *
 * Model khai báo:
 *   protected array $fileAttributes = ['banner_path'];       // cột chứa đường dẫn 1 file
 *   protected array $contentFileAttributes = ['content'];    // cột JSON khối nội dung (khối ảnh: data.image)
 *   protected bool $purgeFilesOnSoftDelete = true;
 */
trait CleansUpFiles
{
    public static function bootCleansUpFiles(): void
    {
        static::updated(function (self $model) {
            $before = $model->collectFilePaths(fn (string $attribute) => $model->getOriginal($attribute));
            $after = $model->collectFilePaths(fn (string $attribute) => $model->getAttribute($attribute));
            $model->deleteFilesIfUnused(array_diff($before, $after));
        });

        static::deleted(function (self $model) {
            $softDeleted = in_array(SoftDeletes::class, class_uses_recursive($model), true) && ! $model->isForceDeleting();
            if ($softDeleted && ! ($model->purgeFilesOnSoftDelete ?? false)) {
                return;
            }
            $model->deleteFilesIfUnused($model->collectFilePaths(fn (string $attribute) => $model->getAttribute($attribute)));
        });
    }

    /** @return list<string> */
    private function collectFilePaths(callable $value): array
    {
        $paths = [];

        foreach ($this->fileAttributes ?? [] as $attribute) {
            $paths[] = $value($attribute);
        }

        foreach ($this->contentFileAttributes ?? [] as $attribute) {
            $blocks = $value($attribute);
            $blocks = is_string($blocks) ? json_decode($blocks, true) : $blocks;
            foreach (is_array($blocks) ? $blocks : [] as $block) {
                $image = $block['data']['image'] ?? null;
                $paths = [...$paths, ...(is_array($image) ? array_values($image) : [$image])];
            }
        }

        return array_values(array_unique(array_filter($paths, fn ($p) => is_string($p) && $p !== '')));
    }

    /** @param  array<string>  $paths */
    private function deleteFilesIfUnused(array $paths): void
    {
        foreach ($paths as $path) {
            if (! $this->fileUsedElsewhere($path)) {
                Storage::disk('public')->delete($path);
            }
        }
    }

    private function fileUsedElsewhere(string $path): bool
    {
        $query = static::query()->whereKeyNot($this->getKey());
        // Có thùng rác (khôi phục được) → bản ghi trong thùng rác vẫn tính là đang dùng file
        if (in_array(SoftDeletes::class, class_uses_recursive($this), true) && ! ($this->purgeFilesOnSoftDelete ?? false)) {
            $query->withTrashed();
        }

        $fileAttributes = $this->fileAttributes ?? [];
        if ($fileAttributes && (clone $query)->where(fn ($q) => collect($fileAttributes)->each(fn ($a) => $q->orWhere($a, $path)))->exists()) {
            return true;
        }

        $contentAttributes = $this->contentFileAttributes ?? [];
        if (! $contentAttributes) {
            return false;
        }

        // Ảnh trong khối nội dung: đọc JSON và so trong PHP (tránh khác biệt LIKE / ký tự \/ giữa MySQL và SQLite)
        return $query->where(fn ($q) => collect($contentAttributes)->each(fn ($a) => $q->orWhereNotNull($a)))
            ->get([$this->getKeyName(), ...$contentAttributes])
            ->contains(fn (self $other) => in_array($path, $other->collectFilePaths(fn ($a) => $other->getAttribute($a)), true));
    }
}
