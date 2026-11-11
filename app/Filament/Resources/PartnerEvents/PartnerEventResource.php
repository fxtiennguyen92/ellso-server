<?php

namespace App\Filament\Resources\PartnerEvents;

use App\Filament\Resources\PartnerEvents\Pages\CreatePartnerEvent;
use App\Filament\Resources\PartnerEvents\Pages\EditPartnerEvent;
use App\Filament\Resources\PartnerEvents\Pages\ListPartnerEvents;
use App\Filament\Resources\PartnerEvents\Schemas\PartnerEventForm;
use App\Models\PartnerEvent;
use App\Support\MediaStorage;
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
 * Marketing › Sự kiện ngoài lề: điểm tin về sự kiện của đối tác bên ngoài (chỉ để học viên biết thêm thông tin).
 * Kéo thả hàng trong danh sách để đổi thứ tự hiện trên app.
 */
class PartnerEventResource extends Resource
{
    protected static ?string $model = PartnerEvent::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static string | UnitEnum | null $navigationGroup = 'Marketing';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Sự kiện ngoài lề';

    protected static ?string $modelLabel = 'sự kiện';

    protected static ?string $pluralModelLabel = 'Sự kiện ngoài lề';

    protected static ?string $recordTitleAttribute = 'title';

    private const TZ = 'Asia/Ho_Chi_Minh';

    public static function form(Schema $schema): Schema
    {
        return PartnerEventForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                ImageColumn::make('image_path')
                    ->label('Ảnh')
                    ->disk(MediaStorage::diskName())
                    ->imageWidth(107)
                    ->imageHeight(60)
                    ->extraImgAttributes(['style' => 'border-radius: 10px; object-fit: cover;']),
                TextColumn::make('title')
                    ->label('Sự kiện')
                    ->description(fn (PartnerEvent $record) => $record->time_text . ' · ' . $record->location)
                    ->searchable()
                    ->wrap(),
                TextColumn::make('period')
                    ->label('Hiển thị trên app')
                    ->state(fn (PartnerEvent $record) => $record->starts_at->timezone(self::TZ)->format('d/m/Y')
                        . ' – ' . $record->ends_at->timezone(self::TZ)->format('d/m/Y'))
                    ->tooltip(fn (PartnerEvent $record) => $record->starts_at->timezone(self::TZ)->format('H:i d/m/Y')
                        . ' – ' . $record->ends_at->timezone(self::TZ)->format('H:i d/m/Y')),
                TextColumn::make('state')
                    ->label('Trạng thái')
                    ->badge()
                    ->state(fn (PartnerEvent $record) => $record->stateLabel())
                    ->color(fn (PartnerEvent $record) => $record->stateColor()),
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
                        ->label(fn (PartnerEvent $record) => $record->is_active ? 'Ẩn khỏi app' : 'Hiển thị lại')
                        ->icon(fn (PartnerEvent $record) => $record->is_active ? Heroicon::EyeSlash : Heroicon::Eye)
                        ->color(fn (PartnerEvent $record) => $record->is_active ? 'warning' : 'success')
                        ->requiresConfirmation()
                        ->action(fn (PartnerEvent $record) => $record->update(['is_active' => ! $record->is_active])),
                    ReplicateAction::make()
                        ->label('Nhân bản')
                        ->modalDescription('Tạo bản sao (đang ẩn) với cùng ảnh, nội dung và thời gian để sửa tiếp.')
                        ->beforeReplicaSaved(function (PartnerEvent $replica) {
                            $replica->title = $replica->title . ' (bản sao)';
                            $replica->is_active = false;
                            $replica->sort_order = (int) PartnerEvent::query()->max('sort_order') + 1;
                            $replica->created_by_id = auth()->id();
                        })
                        ->successRedirectUrl(fn (PartnerEvent $replica) => EditPartnerEvent::getUrl(['record' => $replica])),
                    DeleteAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPartnerEvents::route('/'),
            'create' => CreatePartnerEvent::route('/create'),
            'edit' => EditPartnerEvent::route('/{record}/edit'),
        ];
    }
}
