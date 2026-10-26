<?php

namespace App\Filament\Resources\DocumentTopics;

use App\Filament\Resources\DocumentTopics\Pages\ManageDocumentTopics;
use App\Models\DocumentTopic;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Thiết lập › Chủ đề tài liệu (Tư duy, Lãnh đạo…) — dùng để lọc tài liệu trên app. */
class DocumentTopicResource extends Resource
{
    protected static ?string $model = DocumentTopic::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedTag;

    protected static string | UnitEnum | null $navigationGroup = 'Thiết lập';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Chủ đề tài liệu';

    protected static ?string $modelLabel = 'chủ đề';

    protected static ?string $pluralModelLabel = 'Chủ đề tài liệu';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            TextInput::make('name')->label('Tên chủ đề')->placeholder('VD: Tư duy')->required()->maxLength(80),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->modifyQueryUsing(fn ($query) => $query->withCount('documents'))
            ->columns([
                TextColumn::make('name')->label('Chủ đề')->weight('medium')->searchable(),
                TextColumn::make('documents_count')->label('Số tài liệu')->numeric()->alignCenter(),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    DeleteAction::make()->modalDescription('Tài liệu thuộc chủ đề này sẽ không còn chủ đề.'),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageDocumentTopics::route('/')];
    }
}
