<?php

namespace App\Filament\Resources\TrainingCourses\Pages;

use App\Filament\Resources\TrainingCourses\TrainingCourseResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTrainingCourse extends CreateRecord
{
    protected static string $resource = TrainingCourseResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by_id'] = auth()->id();

        return $data;
    }

    /** Tạo xong → sang tab Lịch tổ chức để thêm ngày. */
    protected function getRedirectUrl(): string
    {
        return ManageTrainingSessions::getUrl(['record' => $this->getRecord()]);
    }
}
