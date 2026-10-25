<?php

namespace App\Filament\Resources\MarketingCampaigns;

use App\Filament\Resources\MarketingCampaigns\Pages\CreateMarketingCampaign;
use App\Filament\Resources\MarketingCampaigns\Pages\EditMarketingCampaign;
use App\Filament\Resources\MarketingCampaigns\Pages\ListMarketingCampaigns;
use App\Filament\Resources\MarketingCampaigns\Schemas\MarketingCampaignForm;
use App\Models\MarketingCampaign;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Marketing › Chiến dịch: banner trên Home của app + trang chi tiết khi bấm vào banner.
 * Kéo thả hàng trong danh sách để đổi thứ tự banner (nhiều chiến dịch hiển thị cùng lúc).
 */
class MarketingCampaignResource extends Resource
{
    protected static ?string $model = MarketingCampaign::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string | UnitEnum | null $navigationGroup = 'Marketing';

    protected static ?int $navigationSort = 0;

    protected static ?string $navigationLabel = 'Chiến dịch';

    protected static ?string $modelLabel = 'chiến dịch';

    protected static ?string $pluralModelLabel = 'Chiến dịch';

    protected static ?string $recordTitleAttribute = 'title';

    private const TZ = 'Asia/Ho_Chi_Minh';

    public static function form(Schema $schema): Schema
    {
        return MarketingCampaignForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                ImageColumn::make('banner_path')
                    ->label('Banner')
                    ->disk('public')
                    ->imageWidth(144)
                    ->imageHeight(60)
                    ->extraImgAttributes(['style' => 'border-radius: 10px; object-fit: cover;']),
                TextColumn::make('title')
                    ->label('Chiến dịch')
                    ->description(fn (MarketingCampaign $record) => $record->summary)
                    ->searchable()
                    ->wrap(),
                TextColumn::make('period')
                    ->label('Thời gian hiển thị')
                    ->state(fn (MarketingCampaign $record) => $record->starts_at->timezone(self::TZ)->format('d/m/Y')
                        . ' – ' . $record->ends_at->timezone(self::TZ)->format('d/m/Y'))
                    ->tooltip(fn (MarketingCampaign $record) => $record->starts_at->timezone(self::TZ)->format('H:i d/m/Y')
                        . ' – ' . $record->ends_at->timezone(self::TZ)->format('H:i d/m/Y')),
                TextColumn::make('state')
                    ->label('Trạng thái')
                    ->badge()
                    ->state(fn (MarketingCampaign $record) => $record->stateLabel())
                    ->color(fn (MarketingCampaign $record) => $record->stateColor()),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->label('Trạng thái')
                    ->options(['live' => 'Đang hiển thị', 'scheduled' => 'Sắp hiển thị', 'ended' => 'Đã kết thúc', 'hidden' => 'Đang ẩn'])
                    ->query(function (Builder $query, array $data) {
                        $now = now();

                        return match ($data['value'] ?? null) {
                            'live' => $query->where('is_active', true)->where('starts_at', '<=', $now)->where('ends_at', '>', $now),
                            'scheduled' => $query->where('is_active', true)->where('starts_at', '>', $now),
                            'ended' => $query->where('is_active', true)->where('ends_at', '<=', $now),
                            'hidden' => $query->where('is_active', false),
                            default => $query,
                        };
                    }),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    Action::make('toggleActive')
                        ->label(fn (MarketingCampaign $record) => $record->is_active ? 'Ẩn khỏi app' : 'Hiển thị lại')
                        ->icon(fn (MarketingCampaign $record) => $record->is_active ? Heroicon::EyeSlash : Heroicon::Eye)
                        ->color(fn (MarketingCampaign $record) => $record->is_active ? 'warning' : 'success')
                        ->requiresConfirmation()
                        ->action(fn (MarketingCampaign $record) => $record->update(['is_active' => ! $record->is_active])),
                    ReplicateAction::make()
                        ->label('Nhân bản')
                        ->modalDescription('Tạo bản sao (đang ẩn) với cùng banner, nội dung và thời gian để sửa tiếp.')
                        ->beforeReplicaSaved(function (MarketingCampaign $replica) {
                            $replica->title = $replica->title . ' (bản sao)';
                            $replica->is_active = false;
                            $replica->created_by_id = auth()->id();
                        })
                        ->successRedirectUrl(fn (MarketingCampaign $replica) => EditMarketingCampaign::getUrl(['record' => $replica])),
                    DeleteAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMarketingCampaigns::route('/'),
            'create' => CreateMarketingCampaign::route('/create'),
            'edit' => EditMarketingCampaign::route('/{record}/edit'),
        ];
    }
}
