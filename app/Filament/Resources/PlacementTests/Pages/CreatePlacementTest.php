<?php

namespace App\Filament\Resources\PlacementTests\Pages;

use App\Filament\Resources\PlacementTests\PlacementTestResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePlacementTest extends CreateRecord
{
    protected static string $resource = PlacementTestResource::class;

    /** Tạo xong → sang tab Phân loại & mức độ (cần có phân loại trước khi đặt điểm cho đáp án). */
    protected function getRedirectUrl(): string
    {
        return ManagePlacementDimensions::getUrl(['record' => $this->getRecord()]);
    }
}
