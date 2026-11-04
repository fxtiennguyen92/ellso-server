<?php

namespace App\Filament\Support;

use App\Enums\RegistrationSource;
use App\Enums\UserRole;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;

/** Cột + trường dùng chung cho danh sách người đăng ký (chương trình huấn luyện, workshop). */
class RegistrantFields
{
    /** Chọn học viên (tìm theo tên / email); ghi chú. */
    public static function form(): array
    {
        return [
            Select::make('user_id')
                ->label('Học viên')
                ->searchable()
                ->getSearchResultsUsing(fn (string $search) => User::query()
                    ->where('role', UserRole::Student)
                    ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
                    ->orderBy('name')
                    ->limit(30)
                    ->get()
                    ->mapWithKeys(fn (User $u) => [$u->id => $u->name . ' · ' . $u->email . ($u->hasActiveMembership() ? '' : ' (chưa có gói)')])
                    ->all())
                ->getOptionLabelUsing(fn ($value) => ($u = User::find($value)) ? $u->name . ' · ' . $u->email : null)
                ->helperText('Học viên cần gói thành viên còn hạn — chưa có thì cấp gói ở Học viên › Tài khoản › Học bạ trước.')
                ->required(),
            TextInput::make('note')
                ->label('Ghi chú')
                ->placeholder('VD: Đã chuyển khoản 1.500.000 ₫ ngày 02/11')
                ->maxLength(300),
        ];
    }

    public static function columns(): array
    {
        return [
            TextColumn::make('user.name')
                ->label('Học viên')
                ->description(fn ($record) => $record->user?->email)
                ->weight('medium')
                ->searchable(),
            TextColumn::make('user.phone')
                ->label('Điện thoại')
                ->placeholder('—')
                ->toggleable(),
            TextColumn::make('source')
                ->label('Nguồn')
                ->badge()
                ->description(fn ($record) => $record->source === RegistrationSource::Admin && $record->createdBy ? 'bởi ' . $record->createdBy->name : null),
            TextColumn::make('note')
                ->label('Ghi chú')
                ->placeholder('—')
                ->wrap(),
            TextColumn::make('created_at')
                ->label('Đăng ký lúc')
                ->dateTime('H:i d/m/Y', 'Asia/Ho_Chi_Minh')
                ->sortable(),
        ];
    }
}
