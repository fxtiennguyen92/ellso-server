<?php

namespace App\Filament\Resources\Courses\Pages;

use App\Enums\CourseStatus;
use App\Filament\Resources\Courses\CourseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ListCourses extends ListRecords
{
    protected static string $resource = CourseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->icon(Heroicon::Plus)
                ->color('primary'),
        ];
    }

    public function getTabs(): array
    {
        $tabs = ['all' => Tab::make('Tất cả')];

        foreach (CourseStatus::cases() as $status) {
            $tabs[$status->value] = Tab::make($status->getLabel())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', $status->value))
                ->badge(fn () => $status === CourseStatus::Pending
                    ? CourseResource::getEloquentQuery()->where('status', $status->value)->count() ?: null
                    : null);
        }

        return $tabs;
    }
}