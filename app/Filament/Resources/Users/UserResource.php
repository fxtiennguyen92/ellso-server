<?php

namespace App\Filament\Resources\Users;

use App\Enums\TempPasswordReason;
use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\ManageUserLogs;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use App\Services\TempPasswordService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;
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
                // Học viên mới: để trống → hệ thống gửi mật khẩu tạm qua email.
                // Giảng viên / admin đăng nhập CMS nên cần mật khẩu ngay.
                ->required(fn (string $operation, Get $get) => $operation === Operation::Create->value
                    && ! self::isStudent($get('role')))
                ->dehydrated(fn (?string $state) => filled($state))
                ->minLength(8)
                ->helperText(fn (string $operation, Get $get) => match (true) {
                    $operation === Operation::Edit->value => 'Để trống nếu không đổi mật khẩu.',
                    self::isStudent($get('role')) => 'Để trống để gửi mật khẩu tạm qua email (học viên đổi mật khẩu ở lần đăng nhập đầu).',
                    default => null,
                }),
            Toggle::make('is_active')
                ->label('Đang hoạt động')
                ->default(true)
                ->inline(false)
                ->disabled(fn (?User $record) => $record?->is(auth()->user())),
            Text::make(fn (?User $record) => self::accountSummary($record))
                ->visible(fn (?User $record) => $record !== null)
                ->columnSpanFull(),
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
            ->modifyQueryUsing(fn (Builder $query) => $query->with('referrer'))
            ->columns([
                TextColumn::make('name')->label('Họ tên')->searchable()->sortable(),
                TextColumn::make('email')->label('Email')->searchable(),
                TextColumn::make('phone')->label('SĐT')->searchable()->placeholder('—'),
                TextColumn::make('referral_code')
                    ->label('Mã giới thiệu')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Đã copy mã')
                    ->fontFamily('mono'),
                TextColumn::make('referrer.name')
                    ->label('Người giới thiệu')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('role')->label('Vai trò')->badge(),
                TextColumn::make('password_changed_at')
                    ->label('Kích hoạt')
                    ->badge()
                    ->state(fn (User $record) => $record->hasActivatedPassword() ? 'Đã kích hoạt' : 'Chưa đăng nhập')
                    ->color(fn (User $record) => $record->hasActivatedPassword() ? 'success' : 'warning')
                    ->tooltip(fn (User $record) => ! $record->hasActivatedPassword() && $record->temp_password_sent_at
                        ? 'Gửi mật khẩu tạm lúc ' . $record->temp_password_sent_at->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y')
                        : null),
                IconColumn::make('is_active')->label('Hoạt động')->boolean(),
                TextColumn::make('last_login_at')
                    ->label('Đăng nhập cuối')
                    ->since()
                    ->dateTimeTooltip('H:i d/m/Y', 'Asia/Ho_Chi_Minh')
                    ->placeholder('Chưa đăng nhập')
                    ->sortable(),
                TextColumn::make('created_at')->label('Ngày tạo')->dateTime('d/m/Y')->sortable(),
            ])
            ->filters([
                SelectFilter::make('role')->label('Vai trò')->options(UserRole::class),
                TernaryFilter::make('is_active')->label('Hoạt động'),
                TernaryFilter::make('activated')
                    ->label('Kích hoạt')
                    ->trueLabel('Đã kích hoạt')
                    ->falseLabel('Chưa đăng nhập')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('password_changed_at'),
                        false: fn (Builder $q) => $q->whereNull('password_changed_at'),
                    ),
            ])
            // Bấm vào hàng = sửa; các thao tác khác trong menu ⋮
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()
                        ->after(function (User $record, array $data) {
                            // Admin đặt mật khẩu trực tiếp → coi như đã kích hoạt, hủy mật khẩu tạm
                            if (filled($data['password'] ?? null)) {
                                $record->forceFill([
                                    'password_changed_at' => now(),
                                    'temp_password' => null,
                                    'temp_password_expires_at' => null,
                                ])->save();
                            }
                        }),
                    Action::make('logs')
                        ->label('Xem nhật ký')
                        ->icon(Heroicon::OutlinedClipboardDocumentList)
                        ->url(fn (User $record) => ManageUserLogs::getUrl(['record' => $record])),
                    Action::make('resendTempPassword')
                        ->label('Gửi lại mật khẩu tạm')
                        ->icon(Heroicon::OutlinedEnvelope)
                        ->color('info')
                        ->visible(fn (User $record) => $record->is_active && ! $record->hasActivatedPassword())
                        ->requiresConfirmation()
                        ->modalDescription(fn (User $record) => "Tạo mật khẩu tạm mới và gửi tới {$record->email}. Mật khẩu tạm gửi trước đó sẽ không dùng được nữa.")
                        ->action(fn (User $record) => self::sendTempPassword($record, TempPasswordReason::AdminResend)),
                    Action::make('resetPassword')
                        ->label('Reset mật khẩu')
                        ->icon(Heroicon::OutlinedKey)
                        ->color('warning')
                        ->visible(fn (User $record) => $record->is_active
                            && $record->hasActivatedPassword()
                            && $record->role === UserRole::Student)
                        ->requiresConfirmation()
                        ->modalDescription(fn (User $record) => "Gửi mật khẩu tạm (hiệu lực "
                            . config('ellso.auth.temp_password_ttl_minutes')
                            . " phút) tới {$record->email}, giống chức năng Quên mật khẩu. Mật khẩu hiện tại của học viên vẫn dùng được.")
                        ->action(fn (User $record) => self::sendTempPassword($record, TempPasswordReason::AdminReset)),
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

    public static function sendTempPassword(User $record, TempPasswordReason $reason): void
    {
        try {
            app(TempPasswordService::class)->issue($record, $reason, request(), auth()->user());

            Notification::make()
                ->title('Đã gửi mật khẩu tạm tới ' . $record->email)
                ->success()
                ->send();
        } catch (Throwable $e) {
            Log::error('Gửi mật khẩu tạm thất bại', ['user_id' => $record->id, 'error' => $e->getMessage()]);

            Notification::make()
                ->title('Không gửi được email')
                ->body('Kiểm tra cấu hình mail (MAIL_*) rồi thử lại.')
                ->danger()
                ->send();
        }
    }

    private static function accountSummary(?User $record): string
    {
        if (! $record) {
            return '';
        }

        $tz = 'Asia/Ho_Chi_Minh';

        return implode(' · ', array_filter([
            'Mã giới thiệu: ' . $record->referral_code,
            'Người giới thiệu: ' . ($record->referrer
                ? "{$record->referrer->name} ({$record->referrer->referral_code})"
                : 'không có'),
            'Đã giới thiệu: ' . $record->referrals()->count() . ' người',
            $record->hasActivatedPassword() ? null : 'Chưa đăng nhập lần đầu',
            'Đăng nhập cuối: ' . ($record->last_login_at?->timezone($tz)->format('H:i d/m/Y') ?? 'chưa có'),
        ]));
    }

    private static function isStudent(mixed $role): bool
    {
        return ($role instanceof UserRole ? $role : UserRole::tryFrom((string) $role)) === UserRole::Student;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUsers::route('/'),
            'logs' => ManageUserLogs::route('/{record}/logs'),
        ];
    }
}
