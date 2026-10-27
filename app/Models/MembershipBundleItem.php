<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Sản phẩm mua kèm được tặng gói thành viên 1 năm.
 * item_type: 'course' (khoá học). Khi có module tài liệu trên server, thêm 'document' vào TYPES.
 */
class MembershipBundleItem extends Model
{
    public const UPDATED_AT = null;

    /** Loại sản phẩm → model. */
    public const TYPES = [
        'course' => Course::class,
    ];

    protected $fillable = ['item_type', 'item_id'];

    public static function typeOf(Model $item): ?string
    {
        return array_search($item::class, self::TYPES, true) ?: null;
    }

    public static function includes(Model $item): bool
    {
        $type = self::typeOf($item);

        return $type !== null
            && static::query()->where('item_type', $type)->where('item_id', $item->getKey())->exists();
    }

    /** @return list<int> id các sản phẩm loại $type đang kèm gói */
    public static function idsOf(string $type): array
    {
        return static::query()->where('item_type', $type)->pluck('item_id')->map(fn ($id) => (int) $id)->all();
    }

    /** Đặt lại danh sách sản phẩm loại $type kèm gói. */
    public static function sync(string $type, array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        static::query()->where('item_type', $type)->whereNotIn('item_id', $ids ?: [0])->delete();

        foreach ($ids as $id) {
            static::query()->firstOrCreate(['item_type' => $type, 'item_id' => $id]);
        }
    }
}
