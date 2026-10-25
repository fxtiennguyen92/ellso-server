<?php

namespace App\Filament\Resources\SystemSettings;

use App\Filament\Resources\SystemSettings\Pages\ManageSystemSettings;
use App\Models\SystemSetting;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Cấu hình hệ thống. Thêm thông số mới: viết migration insert 1 dòng vào system_settings
 * (key, type, label, unit, description…), rồi đọc bằng SystemSetting::get('key').
 */
class SystemSettingResource extends Resource
{
    protected static ?string $model = SystemSetting::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string | UnitEnum | null $navigationGroup = 'Hệ thống';

    protected static ?int $navigationSort = 9;

    protected static ?string $modelLabel = 'cấu hình';

    protected static ?string $pluralModelLabel = 'Cấu hình hệ thống';

    protected static ?string $recordTitleAttribute = 'label';

    public static function form(Schema $schema): Schema
    {
        $isType = fn (string ...$types) => fn (?SystemSetting $record) => in_array($record?->type, $types, true);

        return $schema->components([
            Text::make(fn (?SystemSetting $record) => $record?->description ?? '')
                ->columnSpanFull(),

            TextInput::make('value')
                ->label(fn (?SystemSetting $record) => $record?->label)
                ->numeric()
                ->required()
                ->minValue(0)
                ->maxValue(fn (?SystemSetting $record) => $record?->unit === '%' ? 100 : null)
                ->step(fn (?SystemSetting $record) => $record?->type === 'integer' ? 1 : 'any')
                ->integer(fn (?SystemSetting $record) => $record?->type === 'integer')
                ->suffix(fn (?SystemSetting $record) => $record?->unit)
                ->visible($isType('integer', 'decimal'))
                ->columnSpanFull(),

            Toggle::make('value')
                ->label(fn (?SystemSetting $record) => $record?->label)
                ->formatStateUsing(fn ($state) => filter_var($state, FILTER_VALIDATE_BOOLEAN))
                ->dehydrateStateUsing(fn ($state) => $state ? '1' : '0')
                ->visible($isType('boolean'))
                ->columnSpanFull(),

            TextInput::make('value')
                ->label(fn (?SystemSetting $record) => $record?->label)
                ->suffix(fn (?SystemSetting $record) => $record?->unit)
                ->maxLength(1000)
                ->visible($isType('string'))
                ->columnSpanFull(),

            TextInput::make('value')
                ->label(fn (?SystemSetting $record) => $record?->label)
                ->email()
                ->required()
                ->maxLength(255)
                ->visible($isType('email'))
                ->columnSpanFull(),

            Textarea::make('value')
                ->label(fn (?SystemSetting $record) => $record?->label)
                ->rows(6)
                ->json()
                ->visible($isType('json'))
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->paginated(false)
            // Giá gói thành viên chỉnh ở Marketing › Gói thành viên
            ->modifyQueryUsing(fn ($query) => $query->where('group', '!=', 'membership'))
            ->columns([
                TextColumn::make('label')
                    ->label('Thông số')
                    ->description(fn (SystemSetting $record) => $record->description)
                    ->wrap()
                    ->searchable(),
                TextColumn::make('value')
                    ->label('Giá trị')
                    ->state(fn (SystemSetting $record) => $record->displayValue())
                    ->weight('bold'),
                TextColumn::make('key')
                    ->label('Key')
                    ->fontFamily('mono')
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Cập nhật')
                    ->since()
                    ->dateTimeTooltip('H:i d/m/Y', 'Asia/Ho_Chi_Minh'),
            ])
            ->recordActions([
                EditAction::make()->modalWidth('md'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSystemSettings::route('/'),
        ];
    }
}
