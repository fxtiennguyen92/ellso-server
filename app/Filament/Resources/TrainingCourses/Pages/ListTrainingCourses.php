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
        return 'Chương trình offline: mỗi chương trình gồm nhiều buổi (buổi 1 … buổi n), học viên đăng ký cả chương trình. Cần gói thành viên còn hạn; phí 0 = không thu thêm. Muốn mở lại lần nữa: Nhân bản rồi đặt lịch mới.';
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
