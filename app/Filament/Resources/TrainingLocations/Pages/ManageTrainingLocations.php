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
        return 'Lưu địa điểm một lần (kèm nhãn dễ nhớ) để chọn lại khi xếp lịch khoá huấn luyện. Cũng có thể lưu địa điểm mới ngay khi thêm ngày tổ chức.';
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
