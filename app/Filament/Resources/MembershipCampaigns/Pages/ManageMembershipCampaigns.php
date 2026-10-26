<?php

namespace App\Filament\Resources\MembershipCampaigns\Pages;

use App\Filament\Pages\Marketing\MembershipSettingsPage;
use App\Filament\Resources\MembershipCampaigns\MembershipCampaignResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageMembershipCampaigns extends ManageRecords
{
    protected static string $resource = MembershipCampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('settings')
                ->label('Thiết lập gói')
                ->icon(Heroicon::Cog6Tooth)
                ->color('gray')
                ->url(MembershipSettingsPage::getUrl()),
            CreateAction::make()
                ->label('Tạo chiến dịch')
                ->icon(Heroicon::Plus)
                ->color('primary')
                ->mutateDataUsing(fn (array $data) => [...$data, 'created_by_id' => auth()->id()]),
        ];
    }
}
