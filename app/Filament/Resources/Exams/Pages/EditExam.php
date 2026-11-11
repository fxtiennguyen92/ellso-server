<?php

namespace App\Filament\Resources\Exams\Pages;

use App\Filament\Resources\Exams\ExamResource;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditExam extends EditRecord
{
    protected static string $resource = ExamResource::class;

    protected static ?string $navigationLabel = 'Thông tin';

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedInformationCircle;

    protected static ?string $title = 'Thông tin';

    public function getSubheading(): ?string
    {
        return $this->getRecord()->title;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return ExamResource::cleanData($data, (bool) ($this->data['has_time_limit'] ?? false));
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
