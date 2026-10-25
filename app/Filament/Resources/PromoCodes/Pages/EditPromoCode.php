<?php

namespace App\Filament\Resources\PromoCodes\Pages;

use App\Filament\Resources\PromoCodes\PromoCodeResource;
use App\Models\PromoCode;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPromoCode extends EditRecord
{
    protected static string $resource = PromoCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->modalDescription('Mã sẽ ngừng dùng được. Lịch sử lượt dùng vẫn được giữ, và mã này không thể tạo lại.'),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Mã đã được dùng: không đổi chuỗi mã
        if ($this->getRecord() instanceof PromoCode && $this->getRecord()->used_count > 0) {
            unset($data['code']);
        }

        return $data;
    }
}
