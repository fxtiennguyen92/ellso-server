<?php

namespace App\Filament\Resources\PartnerEvents\Pages;

use App\Filament\Resources\PartnerEvents\PartnerEventResource;
use App\Models\PartnerEvent;
use Filament\Resources\Pages\CreateRecord;

class CreatePartnerEvent extends CreateRecord
{
    protected static string $resource = PartnerEventResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by_id'] = auth()->id();
        // Sự kiện mới xếp cuối danh sách
        $data['sort_order'] = (int) PartnerEvent::query()->max('sort_order') + 1;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
