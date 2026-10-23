<?php

namespace App\Filament\Resources\Courses\Pages;

use App\Enums\CourseStatus;
use App\Filament\Resources\Courses\CourseResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCourse extends CreateRecord
{
    protected static string $resource = CourseResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();

        // Giảng viên: khóa luôn thuộc về mình, chỉ được nháp / chờ duyệt
        if (! $user->isAdmin()) {
            $data['instructor_id'] = $user->id;

            if (! in_array($data['status'] ?? null, array_keys(CourseStatus::instructorOptions()), true)) {
                $data['status'] = CourseStatus::Draft->value;
            }
        }

        return $data;
    }

    /** Tạo xong → sang trang Lộ trình học của khóa. */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('learning-paths', ['record' => $this->getRecord()]);
    }
}
