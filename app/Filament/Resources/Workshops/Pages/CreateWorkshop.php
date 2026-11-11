<?php

namespace App\Filament\Resources\Workshops\Pages;

use App\Filament\Resources\Workshops\Schemas\WorkshopForm;
use App\Filament\Resources\Workshops\WorkshopResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWorkshop extends CreateRecord
{
    protected static string $resource = WorkshopResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by_id'] = auth()->id();

        return WorkshopForm::normalizeCapacity($data, (bool) ($this->data['limit_capacity'] ?? false));
    }

    /** Tạo xong → sang tab Lịch để thêm buổi. */
    protected function getRedirectUrl(): string
    {
        return ManageWorkshopSessions::getUrl(['record' => $this->getRecord()]);
    }
}
