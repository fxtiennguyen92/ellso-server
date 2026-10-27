<?php

namespace App\Filament\Resources\TrainingLocations;

use App\Filament\Resources\TrainingLocations\Pages\ManageTrainingLocations;
use App\Models\TrainingLocation;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Thiết lập › Địa điểm huấn luyện: sổ địa chỉ dùng lại khi xếp lịch khoá huấn luyện. */
class TrainingLocationResource extends Resource
{
    protected static ?string $model = TrainingLocation::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string | UnitEnum | null $navigationGroup = 'Thiết lập';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Địa điểm huấn luyện';

    protected static ?string $modelLabel = 'địa điểm';

    protected static ?string $pluralModelLabel = 'Địa điểm huấn luyện';

    protected static ?string $recordTitleAttribute = 'label';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components(TrainingLocationForm::fields());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('label')
            ->modifyQueryUsing(fn ($query) => $query->withCount('sessions'))
            ->columns([
                TextColumn::make('label')
                    ->label('Nhãn')
                    ->weight('medium')
                    ->searchable(),
                TextColumn::make('address')
                    ->label('Địa chỉ')
                    ->description(fn (TrainingLocation $record) => $record->note)
                    ->searchable()
                    ->wrap(),
                TextColumn::make('map_url')
                    ->label('Bản đồ')
                    ->formatStateUsing(fn () => 'Mở bản đồ')
                    ->url(fn (TrainingLocation $record) => $record->map_url, shouldOpenInNewTab: true)
                    ->color('primary')
                    ->placeholder('—'),
                TextColumn::make('sessions_count')
                    ->label('Số ngày đã dùng')
                    ->numeric()
                    ->alignCenter(),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    DeleteAction::make()
                        ->modalDescription('Chỉ xoá được địa điểm chưa dùng cho ngày tổ chức nào.'),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTrainingLocations::route('/'),
        ];
    }
}
