<?php

namespace App\Filament\Resources\Courses\Pages;

use App\Enums\CourseStatus;
use App\Filament\Resources\Courses\CourseResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditCourse extends EditRecord
{
    protected static string $resource = CourseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('learningPaths')
                ->label('Lộ trình học')
                ->icon(Heroicon::Map)
                ->url(fn () => CourseResource::getUrl('learning-paths', ['record' => $this->getRecord()])),
            ActionGroup::make([
                DeleteAction::make(),
                RestoreAction::make(),
                ForceDeleteAction::make(),
            ]),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $user = auth()->user();

        if (! $user->isAdmin()) {
            unset($data['instructor_id']);

            // Không cho giảng viên tự xuất bản / ẩn
            if (isset($data['status'])
                && ! in_array($data['status'] instanceof CourseStatus ? $data['status']->value : $data['status'], array_keys(CourseStatus::instructorOptions()), true)) {
                unset($data['status']);
            }
        }

        return $data;
    }
}