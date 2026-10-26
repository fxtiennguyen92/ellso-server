<?php

namespace App\Filament\Resources\PlacementAttempts;

use App\Filament\Resources\PlacementAttempts\Pages\ListPlacementAttempts;
use App\Models\PlacementAttempt;
use App\Support\SeenMarker;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Đào tạo › Bài làm đầu vào (ngay dưới Bài kiểm tra đầu vào): bài làm của mọi bài kiểm tra, mới nhất trước.
 * Số đỏ trên menu = số bài làm mới mà admin đang đăng nhập chưa xem (mở danh sách là tính đã xem).
 */
class PlacementAttemptResource extends Resource
{
    protected static ?string $model = PlacementAttempt::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static string | UnitEnum | null $navigationGroup = 'Đào tạo';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Bài làm đầu vào';

    protected static ?string $modelLabel = 'bài làm';

    protected static ?string $pluralModelLabel = 'Bài làm đầu vào';

    protected static ?string $slug = 'placement-attempts';

    public static function newCount(): int
    {
        $since = SeenMarker::get(auth()->user(), SeenMarker::PLACEMENT_ATTEMPTS);

        return PlacementAttempt::query()->when($since, fn ($query) => $query->where('created_at', '>', $since))->count();
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::newCount();

        return $count > 0 ? ($count > 99 ? '99+' : (string) $count) : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): string
    {
        return 'Bài làm mới chưa xem';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlacementAttempts::route('/'),
        ];
    }
}
