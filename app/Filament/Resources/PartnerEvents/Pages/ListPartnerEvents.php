<?php

namespace App\Filament\Resources\PartnerEvents\Pages;

use App\Filament\Resources\PartnerEvents\PartnerEventResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListPartnerEvents extends ListRecords
{
    protected static string $resource = PartnerEventResource::class;

    public function getSubheading(): ?string
    {
        return 'Tin về sự kiện của đối tác bên ngoài để học viên biết thêm thông tin (không đăng ký trong app). App hiện theo thứ tự trong danh sách — bấm "Sắp xếp" rồi kéo thả để đổi thứ tự.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Thêm sự kiện')
                ->icon(Heroicon::Plus)
                ->color('primary'),
        ];
    }
}
