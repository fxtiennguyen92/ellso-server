<?php

namespace App\Filament\Resources\Classifications\Pages;

use App\Filament\Resources\Classifications\ClassificationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListClassifications extends ListRecords
{
    protected static string $resource = ClassificationResource::class;

    public function getSubheading(): ?string
    {
        return 'Câu hỏi: app hỏi học viên khi bắt đầu khoá. Năng lực: lấy tự động từ Bài kiểm tra đầu vào, sửa tên và mức độ ở đó.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->icon(Heroicon::Plus)
                ->color('primary'),
        ];
    }
}
