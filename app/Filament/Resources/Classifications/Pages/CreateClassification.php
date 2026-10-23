<?php

namespace App\Filament\Resources\Classifications\Pages;

use App\Filament\Resources\Classifications\ClassificationResource;
use App\Models\Course;
use Filament\Resources\Pages\CreateRecord;

class CreateClassification extends CreateRecord
{
    protected static string $resource = ClassificationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Giảng viên chỉ tạo phân loại riêng cho khóa của mình
        if (! auth()->user()->isAdmin()) {
            $course = Course::findOrFail($data['course_id'] ?? 0);
            abort_unless(auth()->user()->can('update', $course), 403);
        }

        return $data;
    }
}
