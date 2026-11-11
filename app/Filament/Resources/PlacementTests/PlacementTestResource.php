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
                    TextInput::make('question_count')
                        ->label('Số câu mỗi lần làm')
                        ->integer()
                        ->minValue(1)
                        ->maxValue(500)
                        ->placeholder('Tất cả câu')
                        ->suffix('câu')
                        ->helperText(function (?PlacementTest $record) {
                            $total = $record?->questions()->count();

                            return 'Để trống = học viên làm tất cả câu, theo thứ tự soạn. Nhập số (VD: 10) → mỗi lần làm rút ngẫu nhiên từng ấy câu'
                                . ($total ? " trong {$total} câu đã soạn" : ' trong các câu đã soạn')
                                . ', thứ tự ngẫu nhiên, ưu tiên mỗi năng lực có ít nhất 1 câu. Mức độ tính theo % điểm trên chính bộ câu học viên nhận được.';
                        }),
                    TextInput::make('duration_minutes')
                        ->label('Thời gian làm bài (ước tính)')
                        ->integer()
                        ->minValue(1)
                        ->maxValue(300)
                        ->suffix('phút')
                        ->placeholder('Tự tính: ~1 phút / câu')
                        ->helperText('Chỉ để hiện trên app khi học viên xác nhận bắt đầu (không đếm ngược, không tự nộp bài). Để trống = khoảng 1 phút mỗi câu.'),
                    Toggle::make('shuffle_options')
                        ->label('Đảo thứ tự đáp án')
                        ->default(true)
                        ->helperText('Mỗi học viên thấy đáp án theo thứ tự khác nhau. Điểm vẫn tính theo đáp án được chọn.'),
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
                    ->formatStateUsing(fn (bool $state, PlacementTest $record) => $state ? 'Đang dùng' : ($record->isDraft() ? 'Bản nháp' : 'Không dùng'))
                    ->color(fn (bool $state, PlacementTest $record) => $state ? 'success' : ($record->isDraft() ? 'warning' : 'gray')),
                TextColumn::make('questions_count')
                    ->label('Câu hỏi')
                    ->alignCenter()
                    ->formatStateUsing(fn (int $state, PlacementTest $record) => $record->drawsQuestions() && $record->question_count < $state
                        ? $record->question_count . ' / ' . $state
                        : (string) $state)
                    ->tooltip(fn (PlacementTest $record) => $record->drawsQuestions()
                        ? 'Mỗi lần làm rút ngẫu nhiên ' . $record->questionsPerAttempt((int) $record->questions_count) . ' câu trong ' . $record->questions_count . ' câu'
                        : 'Làm tất cả câu'),
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
                Action::make('openDraft')
                    ->label('Xem bản nháp')
                    ->icon(Heroicon::PencilSquare)
                    ->link()
                    ->color('warning')
                    ->visible(fn (PlacementTest $record) => $record->is_active && $record->siblings_count > 0 && $record->drafts()->isNotEmpty())
                    ->url(fn (PlacementTest $record) => ManagePlacementQuestions::getUrl(['record' => $record->drafts()->first()])),
                ActionGroup::make([
                    EditAction::make()->label('Sửa thông tin'),
                    Action::make('activate')
                        ->label(fn (PlacementTest $record) => $record->isDraft() ? 'Dùng bản nháp này' : 'Dùng bài này')
                        ->icon(Heroicon::CheckBadge)
                        ->color('success')
                        ->visible(fn (PlacementTest $record) => ! $record->is_active)
                        ->requiresConfirmation()
                        ->modalDescription(fn (PlacementTest $record) => ($old = PlacementTest::activeFor($record->key))
                            ? 'Bản nháp này sẽ thay "' . $old->title . '" đang dùng (bản đó được tắt, vẫn giữ để xem lại). Khoá học bắt buộc bài này chuyển sang bản mới; học viên đã làm bản cũ vẫn được tính là đã làm.'
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
                                    'version' => 'Bản nháp để sửa đề',
                                    'new' => 'Bài kiểm tra mới',
                                ])
                                ->descriptions([
                                    'version' => 'Sửa đề trên bản nháp mà không ảnh hưởng bài đang chạy. Xong thì bấm "Dùng bản nháp này" để thay bản cũ; khoá học bắt buộc, điều kiện lộ trình và bài đã làm của học viên vẫn giữ.',
                                    'new' => 'Dùng đề này làm mẫu cho một bài khác, với nhóm năng lực riêng. Hai bài chạy song song được.',
                                ])
                                ->default('version')
                                ->required(),
                        ])
                        ->modalSubmitActionLabel('Nhân bản')
                        ->authorize('replicate')
                        ->action(function (PlacementTest $record, array $data, $livewire) {
                            $asDraft = ($data['mode'] ?? 'version') === 'version';
                            $copy = $record->duplicate($asDraft);
                            Notification::make()->title($asDraft ? 'Đã tạo bản nháp' : 'Đã tạo bài kiểm tra mới')->success()->send();
                            $livewire->redirect(ManagePlacementQuestions::getUrl(['record' => $copy]));
                        }),
                    DeleteAction::make()
                        ->modalDescription('Xoá bài kiểm tra cùng toàn bộ câu hỏi và năng lực.'),
                ]),
            ]);
    }

    /** Ghi chú dưới tên bài: "Đang có bản nháp" / "Bản nháp của …". */
    private static function versionNote(PlacementTest $record): ?string
    {
        if (! $record->siblings_count) {
            return null;
        }
        if ($record->is_active) {
            $drafts = $record->drafts()->count();

            return $drafts > 1 ? "Đang có {$drafts} bản nháp" : ($drafts ? 'Đang có bản nháp' : null);
        }
        if ($active = PlacementTest::activeFor($record->key)) {
            return 'Bản nháp của "' . $active->title . '" (học viên chưa thấy)';
        }

        return 'Có ' . ($record->siblings_count + 1) . ' bản của cùng bài, chưa bản nào đang dùng';
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
