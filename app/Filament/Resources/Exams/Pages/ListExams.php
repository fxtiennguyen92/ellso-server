<?php

namespace App\Filament\Resources\Exams\Pages;

use App\Filament\Resources\Exams\ExamResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListExams extends ListRecords
{
    protected static string $resource = ExamResource::class;

    public function getSubheading(): ?string
    {
        return 'Đề trắc nghiệm dùng chung cho nhiều lộ trình, đậu khi đúng từ 70%. Thêm đề vào lộ trình bằng nút Thêm bài thi ở trang Bài học của lộ trình.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Tạo đề thi')->icon(Heroicon::Plus)->color('primary'),
        ];
    }
}
