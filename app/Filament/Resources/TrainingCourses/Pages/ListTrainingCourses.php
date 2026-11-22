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
        return 'Chương trình offline gồm nhiều buổi, học viên có gói thành viên còn hạn đăng ký cả chương trình. Muốn mở lại lần nữa: Nhân bản rồi đặt lịch mới.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Tạo chương trình')
                ->icon(Heroicon::Plus)
                ->color('primary'),
        ];
    }
}
