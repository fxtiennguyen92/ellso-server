<?php

namespace App\Filament\Resources\Users\Pages;

use App\Enums\TempPasswordReason;
use App\Enums\UserLogEvent;
use App\Filament\Resources\Users\UserResource;
use App\Models\UserLog;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

/** Người dùng › Nhật ký: đăng nhập (thiết bị, thời điểm), gửi mật khẩu tạm, đổi mật khẩu… */
class ManageUserLogs extends ManageRelatedRecords
{
    protected static string $resource = UserResource::class;

    protected static string $relationship = 'logs';

    protected static ?string $title = 'Nhật ký người dùng';

    protected static ?string $breadcrumb = 'Nhật ký';

    public function getSubheading(): string | Htmlable | null
    {
        $user = $this->getOwnerRecord();

        return "{$user->name} · {$user->email}";
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('actor'))
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('created_at')
                    ->label('Thời điểm')
                    ->dateTime('H:i:s d/m/Y', 'Asia/Ho_Chi_Minh')
                    ->sortable(),
                TextColumn::make('event')->label('Sự kiện')->badge(),
                TextColumn::make('device')
                    ->label('Thiết bị')
                    ->state(fn (UserLog $record) => $record->deviceSummary())
                    ->description(fn (UserLog $record) => $record->deviceSummary() ? null : $record->user_agent)
                    ->tooltip(fn (UserLog $record) => $record->device_id ? 'Device ID: ' . $record->device_id : null)
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('ip_address')->label('IP')->placeholder('—')->copyable(),
                TextColumn::make('detail')
                    ->label('Chi tiết')
                    ->state(fn (UserLog $record) => self::detail($record))
                    ->placeholder('—')
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('event')->label('Sự kiện')->options(UserLogEvent::class),
            ]);
    }

    private static function detail(UserLog $log): ?string
    {
        $meta = $log->meta ?? [];
        $parts = [];

        if ($reason = TempPasswordReason::tryFrom($meta['reason'] ?? '')) {
            $parts[] = $reason->getLabel();
        } elseif (($meta['reason'] ?? null) === 'cooldown') {
            $parts[] = 'Gửi lại quá nhanh';
        } elseif (($meta['reason'] ?? null) === 'daily_limit') {
            $parts[] = 'Vượt giới hạn trong ngày';
        }

        if (! empty($meta['referral_code'])) {
            $parts[] = 'Mã giới thiệu: ' . $meta['referral_code'];
        }

        if ($log->actor) {
            $parts[] = 'bởi ' . $log->actor->name;
        }

        return $parts ? implode(' · ', $parts) : null;
    }
}
