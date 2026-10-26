<?php

namespace App\Filament\Resources\PlacementTests\Pages;

use App\Filament\Resources\PlacementTests\PlacementTestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListPlacementTests extends ListRecords
{
    protected static string $resource = PlacementTestResource::class;

    public function getSubheading(): ?string
    {
        return 'Học viên làm bài sau khi đăng ký. Mỗi câu trả lời cộng điểm vào các phân loại (VD: Khả năng tiếp thu); tổng điểm quyết định mức độ (VD: Xuất sắc, Tốt…).';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Tạo bài kiểm tra')
                ->icon(Heroicon::Plus)
                ->color('primary'),
        ];
    }
}
