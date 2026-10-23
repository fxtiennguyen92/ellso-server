<?php

namespace App\Filament\Resources\Classifications;

use App\Filament\Resources\Classifications\Pages\CreateClassification;
use App\Filament\Resources\Classifications\Pages\EditClassification;
use App\Filament\Resources\Classifications\Pages\ListClassifications;
use App\Filament\Resources\Classifications\Schemas\ClassificationForm;
use App\Filament\Resources\Classifications\Tables\ClassificationsTable;
use App\Models\Classification;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ClassificationResource extends Resource
{
    protected static ?string $model = Classification::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string | UnitEnum | null $navigationGroup = 'Thiết lập';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'phân loại';

    protected static ?string $pluralModelLabel = 'Phân loại lộ trình';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return ClassificationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ClassificationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClassifications::route('/'),
            'create' => CreateClassification::route('/create'),
            'edit' => EditClassification::route('/{record}/edit'),
        ];
    }

    /** Giảng viên chỉ thấy phân loại riêng của khóa mình. */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user?->isInstructor()) {
            $query->whereHas('course', fn (Builder $q) => $q->where('instructor_id', $user->id));
        }

        return $query;
    }
}
