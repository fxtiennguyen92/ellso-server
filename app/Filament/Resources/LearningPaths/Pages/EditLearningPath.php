<?php

namespace App\Filament\Resources\LearningPaths\Pages;

use App\Filament\Resources\LearningPaths\LearningPathResource;
use App\Filament\Resources\LearningPaths\Schemas\LearningPathForm;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditLearningPath extends EditRecord
{
    protected static string $resource = LearningPathResource::class;

    /** @var array<int> */
    protected array $conditionOptionIds = [];

    public function getSubheading(): ?string
    {
        return 'Khóa học: ' . $this->getRecord()->course->title;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('lessons')
                ->label('Bài học')
                ->icon(Heroicon::RectangleStack)
                ->url(fn () => LearningPathResource::urlFor('build', $this->getRecord())),
            ActionGroup::make([
                // Xóa xong tự quay về danh sách lộ trình của khóa
                DeleteAction::make(),
            ]),
        ];
    }

    /** Điền điều kiện hiện có vào các ô theo phân loại. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['conditions'] = $this->getRecord()->conditionsByClassification();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->getRecord();

        $this->conditionOptionIds = LearningPathForm::optionIdsFromState($record->course_id, $data['conditions'] ?? []);
        unset($data['conditions']);

        if ($duplicate = LearningPathForm::findDuplicatePath($record->course_id, $this->conditionOptionIds, $record->getKey())) {
            Notification::make()
                ->title('Trùng điều kiện')
                ->body("Lộ trình \"{$duplicate->name}\" đã áp dụng cho đúng các giá trị này.")
                ->danger()
                ->send();

            $this->halt();
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $this->getRecord()->options()->sync($this->conditionOptionIds);
    }
}