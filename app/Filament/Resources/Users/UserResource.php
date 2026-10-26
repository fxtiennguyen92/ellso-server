<?php

namespace App\Filament\Resources\Users;

use App\Enums\MembershipSource;
use App\Enums\TempPasswordReason;
use App\Enums\UserLogEvent;
use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\ManageUserLogs;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use App\Services\MembershipService;
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
use Illuminate\Support\Carbon;
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

    /**
     * Vai trò hiển thị ở màn này. Hệ thống › Người dùng: Admin + Giảng viên.
     * Học viên quản lý ở Tài khoản › Học viên (StudentResource kế thừa màn này).
     *
     * @var list<UserRole>
     */
    protected static array $roles = [UserRole::Admin, UserRole::Instructor];

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('role', array_map(fn (UserRole $r) => $r->value, static::$roles));
    }

    /** @return list<UserRole> */
    public static function roleList(): array
    {
        return static::$roles;
    }

    /** @return array<string, string> */
    public static function roleOptions(): array
    {
        return collect(static::$roles)->mapWithKeys(fn (UserRole $r) => [$r->value => $r->getLabel()])->all();
    }

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
                // Admin vẫn đổi được vai trò (VD: học viên → giảng viên); tạo mới thì theo màn đang mở
                ->options(fn (string $operation) => $operation === Operation::Create->value ? static::roleOptions() : UserRole::class)
                ->default(static::$roles[0])
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
                    self::isStudent($get('role')) => 'Để trống để gửi email mời kèm mật khẩu tạm (học viên đổi mật khẩu ở lần đăng nhập đầu).',
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
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('referrer')
                // Phân biệt người tự đăng ký với người được admin mời (nhãn + email gửi lại)
                ->withExists(['logs as self_registered' => fn (Builder $q) => $q->where('event', UserLogEvent::Registered)])
                // Ngày hết hạn xa nhất của các gói còn hạn (gói nối tiếp tính cả)
                ->withMax(['memberships as membership_ends_at' => fn (Builder $q) => $q->where('ends_at', '>', now())], 'ends_at'))
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
                TextColumn::make('role')->label('Vai trò')->badge()->visible(count(static::$roles) > 1),
                TextColumn::make('membership_ends_at')
                    ->label('Gói thành viên')
                    ->badge()
                    ->visible(in_array(UserRole::Student, static::$roles, true))
                    ->state(fn (User $record) => $record->role === UserRole::Student
                        ? ($record->membership_ends_at
                            ? 'Đến ' . Carbon::parse($record->membership_ends_at)->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y')
                            : 'Chưa có')
                        : null)
                    ->color(fn (User $record) => $record->membership_ends_at ? 'success' : 'gray')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('password_changed_at')
                    ->label('Kích hoạt')
                    ->badge()
                    ->state(fn (User $record) => match (true) {
                        $record->hasActivatedPassword() => 'Đã kích hoạt',
                        $record->wasInvited() => 'Đã mời',
                        default => 'Chưa đăng nhập',
                    })
                    ->color(fn (User $record) => $record->hasActivatedPassword() ? 'success' : 'warning')
                    ->tooltip(fn (User $record) => ! $record->hasActivatedPassword() && $record->temp_password_sent_at
                        ? ($record->wasInvited() ? 'Gửi lời mời lúc ' : 'Gửi mật khẩu tạm lúc ')
                            . $record->temp_password_sent_at->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y')
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
                SelectFilter::make('role')->label('Vai trò')->options(static::roleOptions())->visible(count(static::$roles) > 1),
                TernaryFilter::make('is_active')->label('Hoạt động'),
                TernaryFilter::make('activated')
                    ->label('Kích hoạt')
                    ->trueLabel('Đã kích hoạt')
                    ->falseLabel('Chưa đăng nhập')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('password_changed_at'),
                        false: fn (Builder $query) => $query->whereNull('password_changed_at'),
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
                    ...static::extraRecordActions(),
                    Action::make('logs')
                        ->label('Xem nhật ký')
                        ->icon(Heroicon::ClipboardDocumentList)
                        ->url(fn (User $record) => static::getUrl('logs', ['record' => $record])),
                    ...static::accountActions(),
                    DeleteAction::make(),
                ]),
            ]);
    }

    /**
     * Thao tác với tài khoản (dùng ở danh sách và trang Học bạ): gửi lại email mời / mật khẩu tạm,
     * cấp gói thành viên, reset mật khẩu, khoá / mở khoá.
     *
     * @return list<Action>
     */
    public static function accountActions(): array
    {
        return [
            Action::make('resendTempPassword')
                ->label(fn (User $record) => $record->wasInvited() ? 'Gửi lại email mời' : 'Gửi lại mật khẩu tạm')
                ->icon(Heroicon::Envelope)
                ->color('info')
                ->visible(fn (User $record) => $record->is_active && ! $record->hasActivatedPassword())
                ->requiresConfirmation()
                ->modalDescription(fn (User $record) => ($record->wasInvited() ? 'Gửi lại email mời kèm mật khẩu tạm mới' : 'Tạo mật khẩu tạm mới và gửi')
                    . " tới {$record->email}. Mật khẩu tạm gửi trước đó sẽ không dùng được nữa.")
                ->action(fn (User $record) => self::sendTempPassword($record, TempPasswordReason::AdminResend)),
            Action::make('grantMembership')
                ->label(fn (User $record) => $record->hasActiveMembership() ? 'Gia hạn gói thành viên' : 'Cấp gói thành viên')
                ->icon(Heroicon::Identification)
                ->color('success')
                ->visible(fn (User $record) => $record->role === UserRole::Student)
                ->modalHeading(fn (User $record) => 'Gói thành viên 1 năm cho ' . $record->name)
                ->modalDescription(fn (User $record) => ($end = $record->membershipEndsAt())
                    ? 'Đang có gói đến ' . $end->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y') . '. Gói mới nối tiếp thêm 1 năm, đến '
                        . $end->copy()->addMonths(MembershipService::MONTHS)->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y') . '.'
                    : 'Gói có hiệu lực từ hôm nay đến ' . now()->addMonths(MembershipService::MONTHS)->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y') . '.')
                ->schema([
                    TextInput::make('price')
                        ->label('Số tiền học viên đã trả')
                        ->helperText('Để 0 nếu tặng miễn phí.')
                        ->suffix('₫')
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->default(0),
                    Textarea::make('note')
                        ->label('Ghi chú')
                        ->placeholder('VD: chuyển khoản ngày 25/10, mã giao dịch …')
                        ->rows(2),
                ])
                ->modalSubmitActionLabel('Cấp gói')
                ->action(function (User $record, array $data) {
                    $membership = app(MembershipService::class)->grant(
                        $record,
                        MembershipSource::Admin,
                        (int) ($data['price'] ?? 0),
                        ['note' => $data['note'] ?? null],
                        auth()->user(),
                    );

                    Notification::make()
                        ->title('Đã cấp gói thành viên đến ' . $membership->ends_at->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y'))
                        ->success()
                        ->send();
                }),
            Action::make('resetPassword')
                ->label('Reset mật khẩu')
                ->icon(Heroicon::Key)
                ->color('warning')
                ->visible(fn (User $record) => $record->is_active
                    && $record->hasActivatedPassword()
                    && $record->role === UserRole::Student)
                ->requiresConfirmation()
                ->modalDescription(fn (User $record) => 'Gửi mật khẩu tạm (hiệu lực '
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
        ];
    }

    /** Thao tác thêm trên mỗi dòng (màn con bổ sung, VD: Học bạ). */
    protected static function extraRecordActions(): array
    {
        return [];
    }

    public static function sendTempPassword(User $record, TempPasswordReason $reason): void
    {
        try {
            app(TempPasswordService::class)->issue($record, $reason, request(), auth()->user());

            $isInvite = $reason === TempPasswordReason::AdminCreated
                || ($reason === TempPasswordReason::AdminResend && $record->wasInvited());

            Notification::make()
                ->title(($isInvite ? 'Đã gửi email mời tới ' : 'Đã gửi mật khẩu tạm tới ') . $record->email)
                ->success()
                ->send();
        } catch (Throwable $e) {
            Log::error('Gửi mật khẩu tạm thất bại', ['user_id' => $record->id, 'error' => $e->getMessage()]);

            Notification::make()
                ->title('Không gửi được email')
                ->body($reason === TempPasswordReason::AdminCreated
                    ? 'Tài khoản đã được tạo. Kiểm tra cấu hình mail (MAIL_*) rồi bấm "Gửi lại email mời" trong menu ⋮ của người dùng.'
                    : 'Kiểm tra cấu hình mail (MAIL_*) rồi thử lại.')
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
            $record->role === UserRole::Student
                ? 'Gói thành viên: ' . (($end = $record->membershipEndsAt()) ? 'còn hạn đến ' . $end->timezone($tz)->format('d/m/Y') : 'chưa có')
                : null,
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
