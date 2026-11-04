<?php

namespace App\Filament\Resources\PartnerEvents\Pages;

use App\Filament\Resources\PartnerEvents\PartnerEventResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPartnerEvent extends EditRecord
{
    protected static string $resource = PartnerEventResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
