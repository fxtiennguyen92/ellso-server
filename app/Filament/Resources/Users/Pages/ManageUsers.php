<?php

namespace App\Filament\Resources\Users\Pages;

use App\Enums\TempPasswordReason;
use App\Enums\UserRole;
use App\Filament\Resources\Users\UserResource;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ManageUsers extends ManageRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Tạo tài khoản học viên và gửi email mời kèm mật khẩu tạm
            Action::make('invite')
                ->label('Mời tham gia')
                ->icon(Heroicon::OutlinedEnvelope)
                ->color('success')
                ->authorize('create', User::class)
                ->modalHeading('Mời người dùng tham gia Ellso')
                ->modalDescription('Tạo tài khoản học viên và gửi email mời kèm mật khẩu tạm. Người nhận đăng nhập ứng dụng rồi tự đặt mật khẩu mới.')
                ->modalWidth(Width::Large)
                ->schema([
                    TextInput::make('name')
                        ->label('Họ tên')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->dehydrateStateUsing(fn (?string $state) => Str::lower(trim((string) $state)))
                        ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) {
                            if (User::query()->where('email', Str::lower(trim((string) $value)))->exists()) {
                                $fail('Email này đã có tài khoản.');
                            }
                        }),
                    TextInput::make('phone')
                        ->label('Số điện thoại')
                        ->tel()
                        ->dehydrateStateUsing(fn (?string $state) => RegisterRequest::normalizePhone($state))
                        ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) {
                            $phone = RegisterRequest::normalizePhone($value);
                            if ($phone !== null && ! preg_match('/^0\d{9}$/', $phone)) {
                                $fail('Số điện thoại không hợp lệ (10 số, bắt đầu bằng 0).');
                            }
                        }),
                ])
                ->modalSubmitActionLabel('Tạo và gửi email mời')
                ->action(function (array $data) {
                    $user = DB::transaction(function () use ($data) {
                        $user = new User([
                            'name' => trim($data['name']),
                            'email' => $data['email'],
                            'phone' => $data['phone'] ?? null,
                            'role' => UserRole::Student,
                            'is_active' => true,
                        ]);
                        // Mật khẩu ngẫu nhiên không ai biết; người được mời đăng nhập bằng mật khẩu tạm
                        $user->password = Str::random(64);
                        $user->save();

                        return $user;
                    });

                    UserResource::sendTempPassword($user, TempPasswordReason::AdminCreated);
                }),

            CreateAction::make()
                ->label('Tạo tài khoản')
                ->icon(Heroicon::Plus)
                ->color('gray')
                ->mutateDataUsing(function (array $data): array {
                    if (filled($data['password'] ?? null)) {
                        $data['password_changed_at'] = now();
                    } else {
                        // Không nhập mật khẩu → mật khẩu ngẫu nhiên không ai biết, gửi email mời kèm mật khẩu tạm
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
