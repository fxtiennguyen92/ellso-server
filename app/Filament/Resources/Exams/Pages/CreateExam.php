<?php

namespace App\Filament\Resources\Exams\Pages;

use App\Filament\Resources\Exams\ExamResource;
use Filament\Resources\Pages\CreateRecord;

class CreateExam extends CreateRecord
{
    protected static string $resource = ExamResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return ExamResource::cleanData($data, (bool) ($this->data['has_time_limit'] ?? false));
    }

    /** Tạo xong → soạn câu hỏi. */
    protected function getRedirectUrl(): string
    {
        return ManageExamQuestions::getUrl(['record' => $this->getRecord()]);
    }
}
