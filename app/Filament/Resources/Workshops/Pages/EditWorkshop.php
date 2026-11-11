<?php

namespace App\Filament\Resources\Workshops\Pages;

use App\Filament\Resources\Workshops\Schemas\WorkshopForm;
use App\Filament\Resources\Workshops\WorkshopResource;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditWorkshop extends EditRecord
{
    protected static string $resource = WorkshopResource::class;

    protected static ?string $navigationLabel = 'Thông tin';

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedInformationCircle;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return WorkshopForm::normalizeCapacity($data, (bool) ($this->data['limit_capacity'] ?? false));
    }
}
