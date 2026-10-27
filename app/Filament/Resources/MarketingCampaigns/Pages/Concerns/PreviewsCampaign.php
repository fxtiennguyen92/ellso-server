<?php

namespace App\Filament\Resources\MarketingCampaigns\Pages\Concerns;

use App\Models\MarketingCampaign;
use App\Support\CampaignPreview;
use Filament\Actions\Action;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

/** Nút "Xem trước trên app" cho trang Tạo / Sửa chiến dịch (dùng nội dung đang nhập, chưa cần lưu). */
trait PreviewsCampaign
{
    protected function previewAction(): Action
    {
        return Action::make('preview')
            ->label('Xem trước trên app')
            ->icon(Heroicon::DevicePhoneMobile)
            ->color('gray')
            ->modalHeading('Xem trước trên app')
            ->modalDescription('Thẻ banner trên trang chủ và trang chi tiết khi học viên bấm vào banner. Cuộn trong khung điện thoại để xem hết nội dung.')
            ->modalWidth(Width::SevenExtraLarge)
            ->modalContent(fn () => view('filament.marketing-campaigns.preview', [
                'html' => CampaignPreview::html(
                    $this->form->getRawState(),
                    ($this->record ?? null) instanceof MarketingCampaign ? $this->record : null,
                ),
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Đóng');
    }
}
