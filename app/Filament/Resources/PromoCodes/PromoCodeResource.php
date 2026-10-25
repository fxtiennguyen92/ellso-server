<?php

namespace App\Filament\Resources\PromoCodes;

use App\Enums\PromoCodeStatus;
use App\Filament\Resources\PromoCodes\Pages\CreatePromoCode;
use App\Filament\Resources\PromoCodes\Pages\EditPromoCode;
use App\Filament\Resources\PromoCodes\Pages\ListPromoCodes;
use App\Filament\Resources\PromoCodes\Schemas\PromoCodeForm;
use App\Models\PromoCode;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class PromoCodeResource extends Resource
{
    protected static ?string $model = PromoCode::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string | UnitEnum | null $navigationGroup = 'Marketing';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'mã khuyến mãi';

    protected static ?string $pluralModelLabel = 'Mã khuyến mãi';

    protected static ?string $recordTitleAttribute = 'code';

    public static function form(Schema $schema): Schema
    {
        return PromoCodeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        $tz = 'Asia/Ho_Chi_Minh';

        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('code')
                    ->label('Mã')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Đã copy mã')
                    ->fontFamily('mono')
                    ->weight('bold'),
                TextColumn::make('name')
                    ->label('Chương trình')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('discount')
                    ->label('Mức giảm')
                    ->state(fn (PromoCode $record) => $record->discountSummary())
                    ->description(fn (PromoCode $record) => $record->min_order_amount
                        ? 'Đơn từ ' . PromoCode::money($record->min_order_amount)
                        : null),
                TextColumn::make('period')
                    ->label('Thời gian')
                    ->state(fn (PromoCode $record) => match (true) {
                        ! $record->starts_at && ! $record->ends_at => 'Không giới hạn',
                        default => ($record->starts_at?->timezone($tz)->format('d/m/Y') ?? '…')
                            . ' – ' . ($record->ends_at?->timezone($tz)->format('d/m/Y') ?? '…'),
                    })
                    ->tooltip(fn (PromoCode $record) => collect([
                        $record->starts_at ? 'Từ ' . $record->starts_at->timezone($tz)->format('H:i d/m/Y') : null,
                        $record->ends_at ? 'Đến ' . $record->ends_at->timezone($tz)->format('H:i d/m/Y') : null,
                    ])->filter()->join(' · ') ?: null),
                TextColumn::make('used_count')
                    ->label('Đã dùng')
                    ->state(fn (PromoCode $record) => $record->used_count . ($record->usage_limit ? ' / ' . $record->usage_limit : ''))
                    ->sortable(),
                TextColumn::make('applies_to')
                    ->label('Áp dụng')
                    ->state(fn (PromoCode $record) => $record->applies_to === PromoCode::APPLIES_SELECTED
                        ? collect([
                            $record->courses_count ? $record->courses_count . ' khoá học' : null,
                            $record->categories_count ? $record->categories_count . ' danh mục' : null,
                        ])->filter()->join(', ')
                        : 'Tất cả khoá học')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->state(fn (PromoCode $record) => $record->status()),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount(['courses', 'categories']))
            ->filters([
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options(PromoCodeStatus::class)
                    ->query(fn (Builder $query, array $data) => filled($data['value'])
                        ? $query->withStatus(PromoCodeStatus::from($data['value']))
                        : $query),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    Action::make('toggleActive')
                        ->label(fn (PromoCode $record) => $record->is_active ? 'Tạm dừng' : 'Bật lại')
                        ->icon(fn (PromoCode $record) => $record->is_active ? Heroicon::OutlinedPause : Heroicon::OutlinedPlay)
                        ->color(fn (PromoCode $record) => $record->is_active ? 'warning' : 'success')
                        ->requiresConfirmation()
                        ->action(fn (PromoCode $record) => $record->update(['is_active' => ! $record->is_active])),
                    // Tạo chương trình mới với cùng điều kiện, mã mới, lượt dùng về 0
                    ReplicateAction::make()
                        ->label('Nhân bản')
                        ->modalHeading('Nhân bản mã khuyến mãi')
                        ->modalDescription('Tạo mã mới với cùng mức giảm, thời gian, giới hạn và phạm vi áp dụng.')
                        ->excludeAttributes(['used_count', 'courses_count', 'categories_count'])
                        ->mutateRecordDataUsing(fn (array $data) => [...$data, 'code' => PromoCode::generateUniqueCode()])
                        ->schema(fn (Schema $schema) => $schema->components([
                            \Filament\Forms\Components\TextInput::make('code')
                                ->label('Mã mới')
                                ->required()
                                ->dehydrateStateUsing(fn (?string $state) => PromoCode::normalize($state))
                                ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) {
                                    $code = PromoCode::normalize($value);
                                    if (! preg_match(PromoCode::CODE_PATTERN, $code)) {
                                        $fail('Mã gồm 8–10 ký tự, chỉ chữ in hoa (A–Z) và số.');
                                    } elseif (PromoCode::withTrashed()->where('code', $code)->exists()) {
                                        $fail('Mã này đã tồn tại.');
                                    }
                                }),
                            \Filament\Forms\Components\TextInput::make('name')
                                ->label('Tên chương trình')
                                ->required()
                                ->maxLength(255),
                        ]))
                        ->beforeReplicaSaved(function (PromoCode $replica) {
                            $replica->used_count = 0;
                            $replica->is_active = true;
                            $replica->created_by_id = auth()->id();
                        })
                        ->after(function (PromoCode $replica, PromoCode $record) {
                            $replica->courses()->sync($record->courses()->pluck('courses.id'));
                            $replica->categories()->sync($record->categories()->pluck('categories.id'));
                        })
                        ->successNotificationTitle('Đã tạo mã khuyến mãi mới')
                        ->successRedirectUrl(fn (PromoCode $replica) => EditPromoCode::getUrl(['record' => $replica])),
                    DeleteAction::make()
                        ->modalDescription('Mã sẽ ngừng dùng được. Lịch sử lượt dùng vẫn được giữ, và mã này không thể tạo lại.'),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPromoCodes::route('/'),
            'create' => CreatePromoCode::route('/create'),
            'edit' => EditPromoCode::route('/{record}/edit'),
        ];
    }
}
