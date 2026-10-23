<?php

namespace App\Filament\Resources\Lessons;

use App\Enums\ContentStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Lessons\Pages\EditLessonContent;
use App\Filament\Resources\Lessons\Pages\ManageLessons;
use App\Filament\Resources\Lessons\Schemas\LessonForm;
use App\Models\Lesson;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/** Thư viện bài học dùng chung cho mọi khóa. */
class LessonResource extends Resource
{
    protected static ?string $model = Lesson::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string | UnitEnum | null $navigationGroup = 'Đào tạo';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'bài học';

    protected static ?string $pluralModelLabel = 'Bài học';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return LessonForm::configure($schema)->columns(1);
    }

    public static function table(Table $table): Table
    {
        $isAdmin = (bool) auth()->user()?->isAdmin();

        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                ImageColumn::make('icon')
                    ->label('')
                    ->disk('public')
                    ->circular()
                    ->imageHeight(36)
                    // Icon trắng → đặt trên nền tối
                    ->extraImgAttributes(['style' => 'background:#3E4651;padding:6px']),
                TextColumn::make('title')
                    ->label('Bài học')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Lesson $record) => $record->summary ? (string) str($record->summary)->limit(70) : null),
                TextColumn::make('content_status')
                    ->label('Nội dung')
                    ->state(fn (Lesson $record) => $record->contentStatus())
                    ->badge(),
                TextColumn::make('used_in')
                    ->label('Dùng trong')
                    ->state(fn (Lesson $record) => $record->usedInCourses()->pluck('title')->all())
                    ->badge()
                    ->color('gray')
                    ->limitList(3)
                    ->placeholder('Chưa dùng'),
                TextColumn::make('owner.name')
                    ->label('Người tạo')
                    ->visible($isAdmin),
                TextColumn::make('updated_at')->label('Cập nhật')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('content_status')
                    ->label('Nội dung')
                    ->options(ContentStatus::class)
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereContentStatus(ContentStatus::from($data['value']))
                        : $query),
                Filter::make('unused')
                    ->label('Chưa dùng ở lộ trình nào')
                    ->query(fn (Builder $query) => $query->whereDoesntHave('pathLessons')),
                SelectFilter::make('owner')
                    ->label('Người tạo')
                    ->relationship('owner', 'name', fn (Builder $query) => $query->whereIn('role', [UserRole::Admin->value, UserRole::Instructor->value]))
                    ->visible($isAdmin),
                TrashedFilter::make(),
            ])
            ->recordActions([
                Action::make('content')
                    ->label('Sửa nội dung')
                    ->icon(Heroicon::PencilSquare)
                    ->url(fn (Lesson $record) => static::getUrl('content', ['record' => $record]))
                    ->hidden(fn (Lesson $record) => $record->trashed()),
                // Bấm vào hàng = sửa tên & icon; các thao tác khác trong menu ⋮
                ActionGroup::make([
                    EditAction::make()
                        ->label('Sửa tên & icon')
                        ->modalHeading('Sửa tên & icon')
                        ->modalDescription(fn (Lesson $record) => self::usageWarning($record)),
                    Action::make('duplicate')
                        ->label('Nhân bản')
                        ->icon(Heroicon::DocumentDuplicate)
                        ->authorize('replicate')
                        ->requiresConfirmation()
                        ->modalDescription('Tạo bản sao (tên, icon, bản nháp) để sửa riêng mà không ảnh hưởng các khóa đang dùng bài gốc.')
                        ->action(function (Lesson $record) {
                            $copy = $record->duplicate();
                            Notification::make()->title("Đã tạo \"{$copy->title}\"")->success()->send();
                        }),
                    DeleteAction::make()
                        ->modalDescription(fn (Lesson $record) => self::usageWarning($record)
                            ?? 'Bài học chưa dùng ở lộ trình nào.'),
                    RestoreAction::make(),
                    ForceDeleteAction::make(),
                ]),
            ]);
    }

    public static function usageWarning(Lesson $lesson): ?string
    {
        $courses = $lesson->usedInCourses();

        return $courses->isEmpty()
            ? null
            : 'Bài này đang dùng ở ' . $courses->count() . ' khóa: ' . $courses->pluck('title')->join(', ') . '. Thay đổi sẽ áp dụng cho tất cả.';
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageLessons::route('/'),
            'content' => EditLessonContent::route('/{record}/content'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->visibleTo(auth()->user());
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return static::getEloquentQuery()->withoutTrashed();
    }
}