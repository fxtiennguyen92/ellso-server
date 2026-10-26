<?php

namespace App\Filament\Resources\Documents;

use App\Enums\DocumentType;
use App\Enums\VideoStatus;
use App\Filament\Resources\Documents\Pages\CreateDocument;
use App\Filament\Resources\Documents\Pages\EditDocument;
use App\Filament\Resources\Documents\Pages\ListDocuments;
use App\Filament\Resources\Documents\Schemas\DocumentForm;
use App\Jobs\ProcessDocumentVideo;
use App\Models\Document;
use App\Services\DocumentAccess;
use App\Support\Vnd;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Đào tạo › Tài liệu: Video (HLS 480p/720p, mã hoá), PDF, Sách (PDF / EPUB, cho tải, bán riêng).
 * Quyền xem: App\Services\DocumentAccess. File riêng tư: App\Support\DocumentStorage.
 */
class DocumentResource extends Resource
{
    protected static ?string $model = Document::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedFolderOpen;

    protected static string | UnitEnum | null $navigationGroup = 'Đào tạo';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Tài liệu';

    protected static ?string $modelLabel = 'tài liệu';

    protected static ?string $pluralModelLabel = 'Tài liệu';

    protected static ?string $recordTitleAttribute = 'title';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    public static function form(Schema $schema): Schema
    {
        return DocumentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        $access = app(DocumentAccess::class);

        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['topic', 'links.course', 'links.lesson'])->withCount(['purchases', 'savedBy']))
            ->columns([
                TextColumn::make('cover')
                    ->label('')
                    ->state(fn (Document $record) => self::coverHtml($record))
                    ->html(),
                TextColumn::make('title')
                    ->label('Tài liệu')
                    ->weight('medium')
                    ->description(fn (Document $record) => implode(' · ', array_filter([$record->author, $record->topic?->name])))
                    ->searchable(['title', 'author'])
                    ->wrap(),
                TextColumn::make('type')->label('Loại')->badge(),
                TextColumn::make('access')
                    ->label('Ai được xem')
                    ->state(fn (Document $record) => self::accessHtml($access->conditions($record)))
                    ->html()
                    ->wrap(),
                TextColumn::make('state')
                    ->label('Trạng thái')
                    ->badge()
                    ->state(fn (Document $record) => match (true) {
                        $record->type === DocumentType::Video && $record->video_status !== VideoStatus::Ready => $record->video_status?->getLabel() ?? 'Chưa có video',
                        $record->status === 'published' => 'Đã xuất bản',
                        default => 'Nháp',
                    })
                    ->color(fn (Document $record) => match (true) {
                        $record->type === DocumentType::Video && $record->video_status !== VideoStatus::Ready => $record->video_status?->getColor() ?? 'gray',
                        $record->status === 'published' => 'success',
                        default => 'gray',
                    })
                    ->description(fn (Document $record) => $record->type === DocumentType::Video && $record->video_status === VideoStatus::Ready
                        ? $record->durationLabel() . ' · ' . collect($record->hls_variants)->pluck('height')->map(fn ($h) => $h . 'p')->join(', ')
                        : ($record->file_size ? strtoupper((string) $record->file_format) . ' · ' . number_format($record->file_size / 1048576, 1, ',', '.') . ' MB' : null)),
                TextColumn::make('saved_by_count')->label('Lượt lưu')->numeric()->alignCenter()->toggleable(),
                TextColumn::make('purchases_count')
                    ->label('Đã bán')
                    ->state(fn (Document $record) => $record->isPurchasable() ? $record->purchases_count : null)
                    ->placeholder('—')
                    ->alignCenter()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('document_topic_id')->label('Chủ đề')->relationship('topic', 'name'),
                SelectFilter::make('status')->label('Trạng thái')->options(['draft' => 'Nháp', 'published' => 'Đã xuất bản']),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    Action::make('reprocess')
                        ->label('Xử lý lại video')
                        ->icon(Heroicon::ArrowPath)
                        ->visible(fn (Document $record) => $record->type === DocumentType::Video && $record->video_status === VideoStatus::Failed && $record->video_source_path)
                        ->action(function (Document $record) {
                            $record->forceFill(['video_status' => VideoStatus::Pending, 'video_error' => null])->saveQuietly();
                            ProcessDocumentVideo::dispatch($record->id);
                            Notification::make()->title('Đã đưa video vào hàng đợi xử lý')->success()->send();
                        }),
                    DeleteAction::make()->modalDescription('Xoá tài liệu cùng file (video HLS / PDF / sách) trên kho lưu trữ.'),
                ]),
            ]);
    }

    private static function coverHtml(Document $doc): HtmlString
    {
        $ratio = $doc->type === DocumentType::Book ? 'width:36px;height:52px' : 'width:64px;height:36px';
        $inner = $doc->coverUrl()
            ? '<img src="' . e($doc->coverUrl()) . '" style="width:100%;height:100%;object-fit:cover">'
            : '<span style="color:#fff;opacity:.85;font-size:11px;font-weight:600">' . e($doc->type->getLabel()) . '</span>';

        return new HtmlString('<div style="' . $ratio . ';border-radius:6px;overflow:hidden;display:flex;align-items:center;justify-content:center;background:' . ($doc->cover_color?->hex() ?? '#3E4651') . '">' . $inner . '</div>');
    }

    /** Chip điều kiện xem, cùng màu với app: xanh miễn phí · vàng thành viên · xanh dương bài học · tím khoá học · xám mua riêng. */
    public static function accessHtml(array $conditions): HtmlString
    {
        $chip = fn (string $text, string $bg, string $fg) => '<span style="display:inline-block;padding:1px 8px;margin:2px 4px 2px 0;border-radius:999px;font-size:12px;background:' . $bg . ';color:' . $fg . '">' . e($text) . '</span>';

        $html = collect($conditions)->map(fn (array $c) => match ($c['kind']) {
            'free' => $chip('Miễn phí', '#ECFDF5', '#047857'),
            'plus' => $chip('Gói thành viên', '#FEF3C7', '#92400E'),
            'buy' => $chip('Bán ' . Vnd::full((int) $c['price']), '#F1F5F9', '#0F172A'),
            'lesson' => $chip('Bài: ' . $c['lesson'], '#E0F2FE', '#075985'),
            'course' => $chip('Khoá: ' . $c['course_title'], '#EDE9FE', '#5B21B6'),
            default => '',
        })->join('');

        return new HtmlString($html ?: '<span style="color:var(--danger-600);font-size:12px">Chưa có điều kiện</span>');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDocuments::route('/'),
            'create' => CreateDocument::route('/create'),
            'edit' => EditDocument::route('/{record}/edit'),
        ];
    }
}
