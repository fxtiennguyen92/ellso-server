<?php

namespace App\Filament\Resources\Students\RelationManagers;

use App\Models\AppSession;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Học bạ › Lượt mở app: thời điểm, thời lượng, thiết bị, phiên bản app. */
class AppSessionsRelationManager extends RelationManager
{
    protected static string $relationship = 'appSessions';

    protected static ?string $title = 'Lượt mở app';

    protected static string | \BackedEnum | null $icon = Heroicon::OutlinedDevicePhoneMobile;

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('started_at', 'desc')
            ->paginated([10, 25, 50])
            ->emptyStateHeading('Chưa mở app')
            ->columns([
                TextColumn::make('started_at')->label('Bắt đầu')->dateTime('H:i d/m/Y', 'Asia/Ho_Chi_Minh')->sortable(),
                TextColumn::make('duration')
                    ->label('Thời lượng')
                    ->state(function (AppSession $record) {
                        $m = intdiv($record->durationSeconds(), 60);

                        return $m >= 60 ? intdiv($m, 60) . ' giờ ' . ($m % 60) . ' phút' : max(1, $m) . ' phút';
                    }),
                TextColumn::make('platform')->label('Nền tảng')->formatStateUsing(fn (?string $state) => AppSession::platformLabel($state))->badge()->color('gray'),
                TextColumn::make('device_name')
                    ->label('Thiết bị')
                    ->description(fn (AppSession $record) => trim(($record->os_version ? 'OS ' . $record->os_version : '') . ($record->app_version ? ' · app ' . $record->app_version : ''), ' ·'))
                    ->placeholder('—'),
                TextColumn::make('ip_address')->label('IP')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('platform')->label('Nền tảng')->options(AppSession::PLATFORMS),
            ]);
    }
}
