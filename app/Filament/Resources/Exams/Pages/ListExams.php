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
        return 'Đề thi trắc nghiệm dùng chung cho nhiều lộ trình (thi giữa kỳ, cuối kỳ…). Học viên đậu khi đúng ≥ 70%; đậu mọi bài thi của lộ trình mới được cấp chứng chỉ. Thêm đề vào lộ trình ở trang Bài học của lộ trình.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Tạo đề thi')->icon(Heroicon::Plus)->color('primary'),
        ];
    }
}
