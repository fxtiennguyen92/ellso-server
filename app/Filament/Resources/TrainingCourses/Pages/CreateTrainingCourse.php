<?php

namespace App\Filament\Resources\TrainingCourses\Pages;

use App\Filament\Resources\TrainingCourses\Schemas\TrainingCourseForm;
use App\Filament\Resources\TrainingCourses\TrainingCourseResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTrainingCourse extends CreateRecord
{
    protected static string $resource = TrainingCourseResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by_id'] = auth()->id();

        return TrainingCourseForm::normalizeTrailer($data, $this->data['trailer_type'] ?? 'none');
    }

    /** Tạo xong → sang tab Lịch các buổi để thêm buổi. */
    protected function getRedirectUrl(): string
    {
        return ManageTrainingSessions::getUrl(['record' => $this->getRecord()]);
    }
}
