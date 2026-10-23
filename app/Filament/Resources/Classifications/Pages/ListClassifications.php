<?php

namespace App\Filament\Resources\Classifications\Pages;

use App\Filament\Resources\Classifications\ClassificationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListClassifications extends ListRecords
{
    protected static string $resource = ClassificationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->icon(Heroicon::Plus)
                ->color('success'),
        ];
    }
}