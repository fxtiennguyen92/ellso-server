<?php

namespace App\Filament\Resources\TrainingLocations;

use Filament\Forms\Components\TextInput;

/** Trường của một địa điểm — dùng cho màn Địa điểm huấn luyện và ô "Lưu địa điểm mới" khi xếp lịch. */
class TrainingLocationForm
{
    public static function fields(): array
    {
        return [
            TextInput::make('label')
                ->label('Nhãn')
                ->placeholder('VD: Cafe A – Quận 1')
                ->helperText('Tên ngắn để chọn lại địa điểm này cho các ngày sau.')
                ->required()
                ->maxLength(80),
            TextInput::make('address')
                ->label('Địa chỉ')
                ->placeholder('VD: 12 Nguyễn Huệ, Phường Bến Nghé, Quận 1, TP.HCM')
                ->required()
                ->maxLength(300),
            TextInput::make('map_url')
                ->label('Link bản đồ')
                ->placeholder('https://maps.app.goo.gl/…')
                ->helperText('App mở link này khi học viên bấm "Chỉ đường". Để trống thì app tìm theo địa chỉ.')
                ->url()
                ->maxLength(500),
            TextInput::make('note')
                ->label('Ghi chú cho học viên')
                ->placeholder('VD: Tầng 2, gửi xe phía sau quán')
                ->maxLength(300),
        ];
    }
}
