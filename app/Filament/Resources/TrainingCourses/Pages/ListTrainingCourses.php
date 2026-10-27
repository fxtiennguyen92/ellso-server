<?php

namespace App\Filament\Resources\TrainingCourses\Pages;

use App\Filament\Resources\TrainingCourses\TrainingCourseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListTrainingCourses extends ListRecords
{
    protected static string $resource = TrainingCourseResource::class;

    public function getSubheading(): ?string
    {
        return 'Khoá học offline: mỗi khoá là một buổi, mở vào nhiều ngày ở các địa điểm khác nhau. Giá tính theo khoá.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Tạo khoá huấn luyện')
                ->icon(Heroicon::Plus)
                ->color('primary'),
        ];
    }
}
