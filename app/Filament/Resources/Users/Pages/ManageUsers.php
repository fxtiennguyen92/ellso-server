<?php

namespace App\Filament\Resources\Users\Pages;

use App\Enums\TempPasswordReason;
use App\Enums\UserRole;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class ManageUsers extends ManageRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->icon(Heroicon::Plus)
                ->color('success')
                ->mutateDataUsing(function (array $data): array {
                    if (filled($data['password'] ?? null)) {
                        $data['password_changed_at'] = now();
                    } else {
                        // Không nhập mật khẩu → mật khẩu ngẫu nhiên không ai biết, gửi mật khẩu tạm qua email
                        $data['password'] = Str::random(64);
                        $data['password_changed_at'] = null;
                    }

                    return $data;
                })
                ->after(function (User $record) {
                    if (! $record->hasActivatedPassword()) {
                        UserResource::sendTempPassword($record, TempPasswordReason::AdminCreated);
                    }
                }),
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
