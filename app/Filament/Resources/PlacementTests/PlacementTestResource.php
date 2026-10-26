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
 * Mỗi bài có các tab: Thông tin · Phân loại & mức độ · Câu hỏi · Bài làm.
 * Chấm điểm tự động: App\Services\PlacementScoring.
 */
class PlacementTestResource extends Resource
{
    protected static ?string $model = PlacementTest::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string | UnitEnum | null $navigationGroup = 'Đào tạo';

    protected static ?int $navigationSort = 3;

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
                        ->helperText('Chỉ một bài được dùng. Bật bài này sẽ tự tắt bài đang dùng.'),
                ])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->withCount(['questions', 'dimensions', 'attempts']))
            ->recordUrl(fn (PlacementTest $record) => ManagePlacementQuestions::getUrl(['record' => $record]))
            ->columns([
                TextColumn::make('title')
                    ->label('Bài kiểm tra')
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (PlacementTest $record) => $record->is_active ? 'Học viên đang làm bài này' : null),
                TextColumn::make('is_active')
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'Đang dùng' : 'Không dùng')
                    ->color(fn (bool $state) => $state ? 'success' : 'gray'),
                TextColumn::make('questions_count')->label('Câu hỏi')->numeric()->alignCenter(),
                TextColumn::make('dimensions_count')->label('Năng lực')->numeric()->alignCenter(),
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
                        ->modalDescription('Học viên sẽ làm bài này từ bây giờ; bài đang dùng sẽ được tắt. Kết quả đã có của học viên vẫn giữ nguyên.')
                        ->action(function (PlacementTest $record) {
                            $record->update(['is_active' => true]);
                            Notification::make()->title('Đã chuyển sang dùng "' . $record->title . '"')->success()->send();
                        }),
                    Action::make('duplicate')
                        ->label('Nhân bản')
                        ->icon(Heroicon::DocumentDuplicate)
                        ->requiresConfirmation()
                        ->modalDescription('Tạo bản sao đầy đủ (năng lực, mức độ, câu hỏi, điểm) ở trạng thái chưa dùng — để sửa đề mà không ảnh hưởng bài đang chạy.')
                        ->authorize('replicate')
                        ->action(function (PlacementTest $record, $livewire) {
                            $copy = $record->duplicate();
                            Notification::make()->title('Đã tạo bản sao')->success()->send();
                            $livewire->redirect(ManagePlacementQuestions::getUrl(['record' => $copy]));
                        }),
                    DeleteAction::make()
                        ->modalDescription('Xoá bài kiểm tra cùng toàn bộ câu hỏi và năng lực.'),
                ]),
            ]);
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
