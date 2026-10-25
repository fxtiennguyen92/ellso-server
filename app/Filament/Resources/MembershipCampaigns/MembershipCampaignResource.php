<?php

namespace App\Filament\Resources\MembershipCampaigns;

use App\Filament\Resources\MembershipCampaigns\Pages\ManageMembershipCampaigns;
use App\Models\MembershipCampaign;
use App\Services\MembershipService;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * Marketing › Chiến dịch tặng gói: học viên có tài khoản được tạo trong thời gian chiến dịch
 * (tự đăng ký hoặc được admin mời) được tặng gói thành viên 1 năm.
 */
class MembershipCampaignResource extends Resource
{
    protected static ?string $model = MembershipCampaign::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedGift;

    protected static string | UnitEnum | null $navigationGroup = 'Marketing';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'chiến dịch tặng gói';

    protected static ?string $pluralModelLabel = 'Chiến dịch tặng gói';

    protected static ?string $recordTitleAttribute = 'name';

    private const TZ = 'Asia/Ho_Chi_Minh';

    public static function form(Schema $schema): Schema
    {
        $dateTime = fn (string $name, string $label) => DateTimePicker::make($name)
            ->label($label)
            ->timezone(self::TZ)
            ->native(false)
            ->seconds(false)
            ->displayFormat('H:i d/m/Y')
            ->required();

        return $schema->components([
            TextInput::make('name')
                ->label('Tên chiến dịch')
                ->placeholder('VD: Mừng khai trương – tặng gói 1 năm')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),
            $dateTime('starts_at', 'Bắt đầu'),
            $dateTime('ends_at', 'Kết thúc')
                ->rule(fn (Get $get) => function (string $attribute, mixed $value, Closure $fail) use ($get) {
                    if ($value && $get('starts_at') && Carbon::parse($value)->lte(Carbon::parse($get('starts_at')))) {
                        $fail('Thời điểm kết thúc phải sau thời điểm bắt đầu.');
                    }
                }),
            Textarea::make('description')
                ->label('Ghi chú nội bộ')
                ->rows(2)
                ->columnSpanFull(),
            Toggle::make('is_active')
                ->label('Đang bật')
                ->helperText('Học viên đăng ký trong thời gian chiến dịch được tặng gói thành viên 1 năm ngay khi tạo tài khoản.')
                ->default(true)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('starts_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->withCount('memberships'))
            ->columns([
                TextColumn::make('name')
                    ->label('Chiến dịch')
                    ->description(fn (MembershipCampaign $record) => $record->description)
                    ->searchable()
                    ->wrap(),
                TextColumn::make('period')
                    ->label('Thời gian (giờ VN)')
                    ->state(fn (MembershipCampaign $record) => $record->starts_at->timezone(self::TZ)->format('H:i d/m/Y')
                        . ' – ' . $record->ends_at->timezone(self::TZ)->format('H:i d/m/Y')),
                TextColumn::make('memberships_count')
                    ->label('Đã tặng')
                    ->suffix(' gói')
                    ->sortable(),
                TextColumn::make('state')
                    ->label('Trạng thái')
                    ->badge()
                    ->state(fn (MembershipCampaign $record) => $record->stateLabel())
                    ->color(fn (MembershipCampaign $record) => $record->stateColor()),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    Action::make('toggleActive')
                        ->label(fn (MembershipCampaign $record) => $record->is_active ? 'Tạm dừng' : 'Bật lại')
                        ->icon(fn (MembershipCampaign $record) => $record->is_active ? Heroicon::Pause : Heroicon::Play)
                        ->color(fn (MembershipCampaign $record) => $record->is_active ? 'warning' : 'success')
                        ->requiresConfirmation()
                        ->action(fn (MembershipCampaign $record) => $record->update(['is_active' => ! $record->is_active])),
                    // Chiến dịch tạo sau khi học viên đã đăng ký (hoặc từng tạm dừng): tặng bù
                    Action::make('backfill')
                        ->label('Tặng cho người đã đăng ký')
                        ->icon(Heroicon::Gift)
                        ->color('info')
                        ->visible(fn (MembershipCampaign $record) => $record->starts_at->isPast())
                        ->requiresConfirmation()
                        ->modalHeading('Tặng gói cho học viên đã đăng ký')
                        ->modalDescription(function (MembershipCampaign $record) {
                            $count = app(MembershipService::class)->eligibleForBackfill($record)->count();

                            return $count
                                ? "{$count} học viên đã đăng ký trong thời gian chiến dịch nhưng chưa nhận gói. Tặng mỗi người gói 1 năm, tính từ hôm nay (cộng nối nếu đang có gói)."
                                : 'Mọi học viên đăng ký trong thời gian chiến dịch đều đã nhận gói.';
                        })
                        ->action(function (MembershipCampaign $record) {
                            $count = app(MembershipService::class)->backfillCampaign($record, auth()->user());

                            Notification::make()
                                ->title($count ? "Đã tặng gói cho {$count} học viên" : 'Không có học viên nào cần tặng thêm')
                                ->success()
                                ->send();
                        }),
                    DeleteAction::make()
                        ->modalDescription('Xoá chiến dịch không thu hồi các gói đã tặng.'),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageMembershipCampaigns::route('/'),
        ];
    }
}
