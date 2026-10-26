<?php

namespace App\Filament\Resources\TrainingCourses\Pages;

use App\Filament\Resources\TrainingCourses\TrainingCourseResource;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditTrainingCourse extends EditRecord
{
    protected static string $resource = TrainingCourseResource::class;

    protected static ?string $navigationLabel = 'Thông tin';

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedInformationCircle;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
