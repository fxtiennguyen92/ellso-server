<?php

namespace App\Filament\Resources\Lessons\Pages;

use App\Filament\Resources\Lessons\LessonResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageLessons extends ManageRecords
{
    protected static string $resource = LessonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Thêm bài học')
                ->icon(Heroicon::Plus)
                ->color('primary')
                ->modalHeading('Thêm bài học'),
        ];
    }
}