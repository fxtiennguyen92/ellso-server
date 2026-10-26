<?php

namespace App\Filament\Resources\MarketingCampaigns\Pages;

use App\Filament\Resources\MarketingCampaigns\MarketingCampaignResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListMarketingCampaigns extends ListRecords
{
    protected static string $resource = MarketingCampaignResource::class;

    public function getSubheading(): ?string
    {
        return 'Banner hiện trên trang chủ của app theo thứ tự trong danh sách — bấm "Sắp xếp" rồi kéo thả để đổi thứ tự.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Tạo chiến dịch')
                ->icon(Heroicon::Plus)
                ->color('primary'),
        ];
    }
}
