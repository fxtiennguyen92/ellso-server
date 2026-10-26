<?php

namespace App\Filament\Resources\PromoCodes\Pages;

use App\Filament\Resources\PromoCodes\PromoCodeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListPromoCodes extends ListRecords
{
    protected static string $resource = PromoCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Tạo mã khuyến mãi')
                ->icon(Heroicon::Plus)
                ->color('primary'),
        ];
    }
}
