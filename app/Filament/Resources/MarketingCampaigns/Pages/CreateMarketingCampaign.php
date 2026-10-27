<?php

namespace App\Filament\Resources\MarketingCampaigns\Pages;

use App\Filament\Resources\MarketingCampaigns\MarketingCampaignResource;
use App\Filament\Resources\MarketingCampaigns\Pages\Concerns\PreviewsCampaign;
use App\Models\MarketingCampaign;
use Filament\Resources\Pages\CreateRecord;

class CreateMarketingCampaign extends CreateRecord
{
    use PreviewsCampaign;

    protected static string $resource = MarketingCampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [$this->previewAction()];
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by_id'] = auth()->id();
        // Chiến dịch mới xếp cuối dải banner
        $data['sort_order'] = (int) MarketingCampaign::query()->max('sort_order') + 1;

        return self::cleanCta($data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /** Bỏ thông tin nút không dùng tới theo kiểu nút đã chọn. */
    public static function cleanCta(array $data): array
    {
        $type = $data['cta_type'] ?? MarketingCampaign::CTA_NONE;

        if ($type === MarketingCampaign::CTA_NONE) {
            $data['cta_label'] = null;
        }
        if ($type !== MarketingCampaign::CTA_URL) {
            $data['cta_url'] = null;
        }
        if ($type !== MarketingCampaign::CTA_COURSE) {
            $data['cta_course_id'] = null;
        }

        return $data;
    }
}
