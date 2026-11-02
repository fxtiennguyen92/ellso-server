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
        return 'Có thể dùng nhiều bài cùng lúc, mỗi bài đánh giá một nhóm năng lực (VD: Khả năng tiếp thu). Mỗi câu trả lời cộng điểm vào năng lực; tổng điểm quyết định mức độ (VD: Xuất sắc, Tốt…). Khoá học chọn bài nào bắt buộc làm trước khi học. Kéo thả để đổi thứ tự hiện trên app.';
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
