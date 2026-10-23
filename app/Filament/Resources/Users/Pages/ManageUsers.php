<?php

namespace App\Filament\Resources\Users\Pages;

use App\Enums\UserRole;
use App\Filament\Resources\Users\UserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ManageUsers extends ManageRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->icon(Heroicon::Plus)
                ->color('success'),
        ];
    }

    public function getTabs(): array
    {
        $tabs = ['all' => Tab::make('Tất cả')];

        foreach (UserRole::cases() as $role) {
            $tabs[$role->value] = Tab::make($role->getLabel())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('role', $role->value));
        }

        return $tabs;
    }
}