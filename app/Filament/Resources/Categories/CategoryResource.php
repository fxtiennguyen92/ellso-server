<?php

namespace App\Filament\Resources\Categories;

use App\Filament\Resources\Categories\Pages\ManageCategories;
use App\Models\Category;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedFolder;

    protected static string | UnitEnum | null $navigationGroup = 'Thiết lập';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'danh mục';

    protected static ?string $pluralModelLabel = 'Danh mục khóa học';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Tên danh mục')
                ->required()
                ->maxLength(255),
            TextInput::make('slug')
                ->label('Slug')
                ->helperText('Để trống để tự sinh từ tên.')
                ->unique(ignoreRecord: true)
                ->maxLength(255),
            Select::make('parent_id')
                ->label('Danh mục cha')
                ->relationship(
                    'parent',
                    'name',
                    fn (Builder $query, ?Category $record) => $query
                        ->whereNull('parent_id') // chỉ cho phép 2 cấp
                        ->when($record, fn ($q) => $q->whereKeyNot($record->getKey())),
                )
                ->searchable()
                ->preload(),
            TextInput::make('sort_order')
                ->label('Thứ tự')
                ->numeric()
                ->default(0),
            Textarea::make('description')
                ->label('Mô tả')
                ->rows(3)
                ->columnSpanFull(),
            Toggle::make('is_active')
                ->label('Hiển thị')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('name')->label('Tên')->searchable(),
                TextColumn::make('parent.name')->label('Danh mục cha')->placeholder('—'),
                TextColumn::make('courses_count')->label('Số khóa học')->counts('courses'),
                IconColumn::make('is_active')->label('Hiển thị')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Hiển thị'),
            ])
            // Bấm vào hàng = sửa; các thao tác khác trong menu ⋮
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCategories::route('/'),
        ];
    }
}
