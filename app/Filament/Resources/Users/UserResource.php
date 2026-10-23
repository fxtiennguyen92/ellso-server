<?php

namespace App\Filament\Resources\Users;

use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Operation;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string | UnitEnum | null $navigationGroup = 'Hệ thống';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'người dùng';

    protected static ?string $pluralModelLabel = 'Người dùng';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Họ tên')
                ->required()
                ->maxLength(255),
            TextInput::make('email')
                ->label('Email')
                ->email()
                ->required()
                ->unique(ignoreRecord: true),
            TextInput::make('phone')
                ->label('Số điện thoại')
                ->tel()
                ->maxLength(20),
            Select::make('role')
                ->label('Vai trò')
                ->options(UserRole::class)
                ->default(UserRole::Student)
                ->required()
                ->live()
                // Không cho admin tự hạ quyền của chính mình
                ->disabled(fn (?User $record) => $record?->is(auth()->user())),
            TextInput::make('password')
                ->label('Mật khẩu')
                ->password()
                ->revealable()
                ->required(fn (string $operation) => $operation === Operation::Create->value)
                ->dehydrated(fn (?string $state) => filled($state))
                ->minLength(8)
                ->helperText(fn (string $operation) => $operation === Operation::Edit->value
                    ? 'Để trống nếu không đổi mật khẩu.'
                    : null),
            Toggle::make('is_active')
                ->label('Đang hoạt động')
                ->default(true)
                ->inline(false)
                ->disabled(fn (?User $record) => $record?->is(auth()->user())),
            Text::make(fn (?User $record) => 'Câu trả lời phân loại gần nhất: '
                    . ($record?->classificationOptions()->with('classification')->get()
                        ->map(fn ($o) => $o->classification->name . ': ' . $o->name)->join(' · ') ?: 'chưa có'))
                ->visible(fn (Get $get, ?User $record) => $record && self::isStudent($get('role')))
                ->columnSpanFull(),
            Textarea::make('bio')
                ->label('Giới thiệu')
                ->rows(3)
                ->columnSpanFull()
                ->visible(fn (Get $get) => ! self::isStudent($get('role'))),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->label('Họ tên')->searchable()->sortable(),
                TextColumn::make('email')->label('Email')->searchable(),
                TextColumn::make('phone')->label('SĐT')->searchable()->placeholder('—'),
                TextColumn::make('role')->label('Vai trò')->badge(),
                IconColumn::make('is_active')->label('Hoạt động')->boolean(),
                TextColumn::make('created_at')->label('Ngày tạo')->dateTime('d/m/Y')->sortable(),
            ])
            ->filters([
                SelectFilter::make('role')->label('Vai trò')->options(UserRole::class),
                TernaryFilter::make('is_active')->label('Hoạt động'),
            ])
            // Bấm vào hàng = sửa; các thao tác khác trong menu ⋮
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    Action::make('toggleActive')
                        ->label(fn (User $record) => $record->is_active ? 'Khóa' : 'Mở khóa')
                        ->icon(fn (User $record) => $record->is_active ? Heroicon::LockClosed : Heroicon::LockOpen)
                        ->color(fn (User $record) => $record->is_active ? 'danger' : 'success')
                        ->requiresConfirmation()
                        ->hidden(fn (User $record) => $record->is(auth()->user()))
                        ->action(function (User $record) {
                            $record->update(['is_active' => ! $record->is_active]);

                            // Khóa tài khoản → thu hồi token API của app
                            if (! $record->is_active) {
                                $record->tokens()->delete();
                            }
                        }),
                    DeleteAction::make(),
                ]),
            ]);
    }

    private static function isStudent(mixed $role): bool
    {
        return ($role instanceof UserRole ? $role : UserRole::tryFrom((string) $role)) === UserRole::Student;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUsers::route('/'),
        ];
    }
}