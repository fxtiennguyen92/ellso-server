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
        return 'Tin sự kiện của đối tác, chỉ để học viên biết, không đăng ký trong app. Bấm "Sắp xếp" rồi kéo thả để đổi thứ tự trên app.';
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
