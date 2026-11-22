<?php

namespace App\Filament\Resources\Workshops\Pages;

use App\Filament\Resources\Workshops\WorkshopResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListWorkshops extends ListRecords
{
    protected static string $resource = WorkshopResource::class;

    public function getSubheading(): ?string
    {
        return 'Workshop online một buổi, mở lại vào nhiều ngày; học viên có gói thành viên còn hạn đăng ký từng buổi. Link họp dán ở tab Lịch.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Tạo workshop')
                ->icon(Heroicon::Plus)
                ->color('primary'),
        ];
    }
}
