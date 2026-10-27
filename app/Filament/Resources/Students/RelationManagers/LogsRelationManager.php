<?php

namespace App\Filament\Resources\Students\RelationManagers;

use App\Enums\UserLogEvent;
use App\Filament\Resources\Users\Pages\ManageUserLogs;
use App\Models\UserLog;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Học bạ › Nhật ký: đăng nhập (thiết bị, IP, thời điểm), mật khẩu tạm, đổi mật khẩu… */
class LogsRelationManager extends RelationManager
{
    protected static string $relationship = 'logs';

    protected static ?string $title = 'Nhật ký';

    protected static string | \BackedEnum | null $icon = Heroicon::OutlinedClipboardDocumentList;

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('actor'))
            ->defaultSort('id', 'desc')
            ->paginated([10, 25, 50])
            ->columns([
                TextColumn::make('created_at')->label('Thời điểm')->dateTime('H:i d/m/Y', 'Asia/Ho_Chi_Minh'),
                TextColumn::make('event')->label('Sự kiện')->badge(),
                TextColumn::make('device')
                    ->label('Thiết bị')
                    ->state(fn (UserLog $record) => $record->deviceSummary())
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('ip_address')->label('IP')->placeholder('—'),
                TextColumn::make('detail')->label('Chi tiết')->state(fn (UserLog $record) => ManageUserLogs::detail($record))->placeholder('—')->wrap(),
            ])
            ->filters([
                SelectFilter::make('event')->label('Sự kiện')->options(UserLogEvent::class),
            ]);
    }
}
