<?php

namespace App\Filament\Resources\TrainingLocations\Pages;

use App\Filament\Resources\TrainingLocations\TrainingLocationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageTrainingLocations extends ManageRecords
{
    protected static string $resource = TrainingLocationResource::class;

    public function getSubheading(): ?string
    {
        return 'Lưu địa điểm một lần để chọn lại khi xếp lịch chương trình huấn luyện. Cũng có thể thêm địa điểm mới ngay lúc thêm buổi.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Thêm địa điểm')
                ->icon(Heroicon::Plus)
                ->color('primary'),
        ];
    }
}
