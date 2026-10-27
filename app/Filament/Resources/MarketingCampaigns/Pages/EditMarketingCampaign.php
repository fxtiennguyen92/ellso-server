<?php

namespace App\Filament\Resources\MarketingCampaigns\Pages;

use App\Filament\Resources\MarketingCampaigns\MarketingCampaignResource;
use App\Filament\Resources\MarketingCampaigns\Pages\Concerns\PreviewsCampaign;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMarketingCampaign extends EditRecord
{
    use PreviewsCampaign;

    protected static string $resource = MarketingCampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->previewAction(),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return CreateMarketingCampaign::cleanCta($data);
    }
}
