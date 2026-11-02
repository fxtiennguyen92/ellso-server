<?php

namespace App\Filament\Resources\PlacementTests;

use App\Filament\Resources\PlacementTests\Pages\CreatePlacementTest;
use App\Filament\Resources\PlacementTests\Pages\EditPlacementTest;
use App\Filament\Resources\PlacementTests\Pages\ListPlacementTests;
use App\Filament\Resources\PlacementTests\Pages\ManagePlacementAttempts;
use App\Filament\Resources\PlacementTests\Pages\ManagePlacementDimensions;
use App\Filament\Resources\PlacementTests\Pages\ManagePlacementQuestions;
use App\Models\PlacementTest;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Resources\Pages\Page;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Đào tạo › Bài kiểm tra đầu vào.
 * Có thể dùng nhiều bài cùng lúc, mỗi bài đánh giá một nhóm năng lực riêng; khoá học chọn bài bắt buộc.
 * Mỗi bài có các tab: Câu hỏi · Năng lực & mức độ · Bài làm · Thông tin.
 * Chấm điểm tự động: App\Services\PlacementScoring.
 */
class PlacementTestResource extends Resource
{
    protected static ?string $model = PlacementTest::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string | UnitEnum | null $navigationGroup = 'Đào tạo';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Bài kiểm tra đầu vào';

    protected static ?string $modelLabel = 'bài kiểm tra đầu vào';

    protected static ?string $pluralModelLabel = 'Bài kiểm tra đầu vào';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->schema([
                    TextInput::make('title')
                        ->label('Tên bài kiểm tra')
                        ->required()
                        ->maxLength(150),
                    Textarea::make('intro')
                        ->label('Lời giới thiệu')
                        ->rows(4)
                        ->helperText('Hiện trên app trước khi học viên bắt đầu làm bài. VD: mục đích, thời gian làm khoảng 5 phút, không có đáp án đúng/sai.'),
                    Toggle::make('is_active')
                        ->label('Đang dùng trên app')
                        ->helperText('Có thể dùng nhiều bài cùng lúc. Bật một phiên bản mới (bản nhân bản) sẽ tự tắt phiên bản cũ của cùng bài.'),
                ])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->modifyQueryUsing(fn ($query) => $query->withCount(['questions', 'dimensions', 'attempts', 'requiringCourses', 'siblings']))
            ->recordUrl(fn (PlacementTest $record) => ManagePlacementQuestions::getUrl(['record' => $record]))
            ->columns([
                TextColumn::make('title')
                    ->label('Bài kiểm tra')
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (PlacementTest $record) => self::versionNote($record)),
                TextColumn::make('is_active')
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'Đang dùng' : 'Không dùng')
                    ->color(fn (bool $state) => $state ? 'success' : 'gray'),
                TextColumn::make('questions_count')->label('Câu hỏi')->numeric()->alignCenter(),
                TextColumn::make('dimensions_count')->label('Năng lực')->numeric()->alignCenter(),
                TextColumn::make('requiring_courses_count')
                    ->label('Khoá bắt buộc')
                    ->numeric()
                    ->alignCenter()
                    ->tooltip(fn (PlacementTest $record) => $record->requiring_courses_count
                        ? $record->requiringCourses()->orderBy('title')->pluck('title')->join(', ')
                        : null),
                TextColumn::make('attempts_count')->label('Bài làm')->numeric()->alignCenter(),
                TextColumn::make('updated_at')
                    ->label('Cập nhật')
                    ->dateTime('H:i d/m/Y', 'Asia/Ho_Chi_Minh')
                    ->color('gray'),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()->label('Sửa thông tin'),
                    Action::make('activate')
                        ->label('Dùng bài này')
                        ->icon(Heroicon::CheckBadge)
                        ->color('success')
                        ->visible(fn (PlacementTest $record) => ! $record->is_active)
                        ->requiresConfirmation()
                        ->modalDescription(fn (PlacementTest $record) => ($old = PlacementTest::activeFor($record->key))
                            ? 'Đây là phiên bản mới của "' . $old->title . '" — phiên bản đó sẽ được tắt. Khoá học bắt buộc bài này chuyển sang phiên bản mới; học viên đã làm phiên bản cũ vẫn được tính là đã làm.'
                            : 'Học viên sẽ thấy và làm được bài này trên app. Các bài đang dùng khác vẫn giữ nguyên.')
                        ->action(function (PlacementTest $record) {
                            $record->update(['is_active' => true]);
                            Notification::make()->title('Đang dùng "' . $record->title . '"')->success()->send();
                        }),
                    Action::make('deactivate')
                        ->label('Ngừng dùng')
                        ->icon(Heroicon::NoSymbol)
                        ->color('gray')
                        ->visible(fn (PlacementTest $record) => $record->is_active)
                        ->requiresConfirmation()
                        ->modalDescription(fn (PlacementTest $record) => 'Học viên không làm được bài này nữa. Kết quả đã có vẫn giữ nguyên.'
                            . ($record->requiring_courses_count
                                ? ' ' . $record->requiring_courses_count . ' khoá học đang bắt buộc bài này sẽ tạm thời không bắt buộc nữa.'
                                : ''))
                        ->action(function (PlacementTest $record) {
                            $record->update(['is_active' => false]);
                            Notification::make()->title('Đã ngừng dùng "' . $record->title . '"')->success()->send();
                        }),
                    Action::make('duplicate')
                        ->label('Nhân bản')
                        ->icon(Heroicon::DocumentDuplicate)
                        ->modalDescription('Tạo bản sao đầy đủ (năng lực, mức độ, câu hỏi, điểm) ở trạng thái chưa dùng.')
                        ->schema([
                            Radio::make('mode')
                                ->hiddenLabel()
                                ->options([
                                    'version' => 'Phiên bản mới của bài này',
                                    'new' => 'Bài kiểm tra mới',
                                ])
                                ->descriptions([
                                    'version' => 'Để sửa đề mà không ảnh hưởng bài đang chạy. Khi bật bản sao, bản cũ tự tắt; khoá học bắt buộc, điều kiện lộ trình và bài đã làm của học viên vẫn giữ.',
                                    'new' => 'Dùng đề này làm mẫu cho một bài khác, với nhóm năng lực riêng. Hai bài chạy song song được.',
                                ])
                                ->default('version')
                                ->required(),
                        ])
                        ->modalSubmitActionLabel('Nhân bản')
                        ->authorize('replicate')
                        ->action(function (PlacementTest $record, array $data, $livewire) {
                            $copy = $record->duplicate(($data['mode'] ?? 'version') === 'version');
                            Notification::make()->title('Đã tạo bản sao')->success()->send();
                            $livewire->redirect(ManagePlacementQuestions::getUrl(['record' => $copy]));
                        }),
                    DeleteAction::make()
                        ->modalDescription('Xoá bài kiểm tra cùng toàn bộ câu hỏi và năng lực.'),
                ]),
            ]);
    }

    /** "Phiên bản mới của …" / "Có 2 phiên bản" — để phân biệt các bản nhân bản. */
    private static function versionNote(PlacementTest $record): ?string
    {
        if (! $record->siblings_count) {
            return null;
        }
        if (! $record->is_active && ($active = PlacementTest::activeFor($record->key))) {
            return 'Phiên bản khác của "' . $active->title . '" (đang dùng)';
        }

        return 'Có ' . ($record->siblings_count + 1) . ' phiên bản';
    }

    public static function getRecordSubNavigation(Page $page): array
    {
        return $page->generateNavigationItems([
            ManagePlacementQuestions::class,
            ManagePlacementDimensions::class,
            ManagePlacementAttempts::class,
            EditPlacementTest::class,
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlacementTests::route('/'),
            'create' => CreatePlacementTest::route('/create'),
            'edit' => EditPlacementTest::route('/{record}/edit'),
            'questions' => ManagePlacementQuestions::route('/{record}/questions'),
            'dimensions' => ManagePlacementDimensions::route('/{record}/dimensions'),
            'attempts' => ManagePlacementAttempts::route('/{record}/attempts'),
        ];
    }
}
