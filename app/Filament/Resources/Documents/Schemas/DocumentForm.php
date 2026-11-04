<?php

namespace App\Filament\Resources\Documents\Schemas;

use App\Enums\ChapterColor;
use App\Enums\DocumentType;
use App\Enums\UserRole;
use App\Enums\VideoStatus;
use App\Models\Document;
use App\Models\DocumentTopic;
use App\Support\DocumentStorage;
use App\Support\VideoChapters;
use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use App\Support\MediaStorage;

class DocumentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(['default' => 1, 'lg' => 3])->components([
            Group::make([
                Section::make('Thông tin tài liệu')
                    ->columns(2)
                    ->schema([
                        ToggleButtons::make('type')
                            ->label('Loại')
                            ->options(DocumentType::class)
                            ->default(DocumentType::Video)
                            ->inline()
                            ->required()
                            ->live()
                            ->disabledOn('edit')
                            ->columnSpanFull(),
                        TextInput::make('title')->label('Tên tài liệu')->required()->maxLength(200)->columnSpanFull(),
                        ToggleButtons::make('author_kind')
                            ->label('Tác giả')
                            ->options(['teacher' => 'Giảng viên Ellso', 'external' => 'Tác giả ngoài'])
                            ->icons(['teacher' => Heroicon::OutlinedAcademicCap, 'external' => Heroicon::OutlinedUser])
                            ->default('teacher')
                            ->inline()
                            ->live()
                            ->dehydrated(false)
                            ->afterStateHydrated(fn (ToggleButtons $component, ?Document $record) => $record && $component->state($record->author_id || ! $record->author ? 'teacher' : 'external'))
                            ->columnSpanFull(),
                        Select::make('author_id')
                            ->label('Giảng viên')
                            ->relationship('authorUser', 'name', fn (Builder $query) => $query
                                ->whereIn('role', [UserRole::Instructor->value, UserRole::Admin->value])
                                ->orderBy('name'))
                            ->searchable()
                            ->preload()
                            ->helperText('Tài liệu sẽ hiện trong hồ sơ của giảng viên này.')
                            ->visible(fn (Get $get) => $get('author_kind') !== 'external'),
                        TextInput::make('author')
                            ->label('Tên tác giả')
                            ->maxLength(120)
                            ->placeholder('VD: Yuval Noah Harari')
                            ->visible(fn (Get $get) => $get('author_kind') === 'external'),
                        Select::make('document_topic_id')
                            ->label('Chủ đề')
                            ->relationship('topic', 'name', fn ($query) => $query->orderBy('sort_order'))
                            ->preload()
                            ->searchable()
                            ->helperText('Dùng để lọc trên app.')
                            ->createOptionForm([TextInput::make('name')->label('Tên chủ đề')->required()->maxLength(80)])
                            ->createOptionUsing(fn (array $data) => DocumentTopic::create([...$data, 'sort_order' => (int) DocumentTopic::max('sort_order') + 1])->getKey()),
                        Textarea::make('description')->label('Mô tả')->rows(3)->columnSpanFull(),
                    ]),

                Section::make(fn (Get $get) => match (self::type($get)) {
                    DocumentType::Video => 'Video',
                    DocumentType::Book => 'File sách',
                    default => 'File PDF',
                })
                    ->schema([
                        // Video: tải file gốc → hệ thống cắt HLS 480p / 720p (chạy nền)
                        Callout::make(fn (?Document $record) => self::videoStatus($record))
                            ->visible(fn (Get $get, ?Document $record) => self::type($get) === DocumentType::Video && $record?->video_status)
                            ->color(fn (?Document $record) => $record?->video_status?->getColor() ?? 'gray'),
                        FileUpload::make('video_source_path')
                            ->label(fn (?Document $record) => $record?->hls_path ? 'Thay video' : 'File video')
                            ->disk(DocumentStorage::diskName())
                            ->directory('uploads/videos')
                            ->visibility('private')
                            ->moveFiles() // production: file tạm Livewire cùng ổ S3 → move ngay trong S3, không tải 2 GB qua server
                            ->acceptedFileTypes(['video/mp4', 'video/quicktime', 'video/x-matroska', 'video/webm'])
                            ->maxSize((int) config('ellso.documents.video_max_upload_mb') * 1024)
                            ->previewable(false)
                            ->required(fn (Get $get, ?Document $record) => self::type($get) === DocumentType::Video && ! $record?->hls_path)
                            ->helperText('MP4 / MOV / MKV / WebM, tối đa ' . config('ellso.documents.video_max_upload_mb') . ' MB. Sau khi lưu, hệ thống tự cắt thành HLS 480p + 720p (mã hoá) và đẩy lên kho lưu trữ — mất vài phút tuỳ độ dài video.')
                            ->visible(fn (Get $get) => self::type($get) === DocumentType::Video),
                        Textarea::make('video_chapters_text')
                            ->label('Mốc thời gian')
                            ->rows(6)
                            ->placeholder("00:00 Giới thiệu\n02:15 Cài đặt công cụ\n10:40 Thực hành\n1:05:00 Tổng kết")
                            ->helperText('Mỗi dòng một mốc: "mm:ss Nội dung" (video dài hơn 1 giờ dùng "h:mm:ss"). Thứ tự tự sắp theo thời gian. Trên app, bấm vào mốc sẽ tua video tới đó.')
                            ->afterStateHydrated(fn (Textarea $component, ?Document $record) => $component->state(VideoChapters::format($record?->video_chapters)))
                            ->rule(fn (?Document $record) => function (string $attribute, $value, Closure $fail) use ($record) {
                                foreach (VideoChapters::parse($value, $record?->duration_seconds)['errors'] as $error) {
                                    $fail($error);
                                }
                            })
                            ->dehydrateStateUsing(fn (?string $state) => $state)
                            ->visible(fn (Get $get) => self::type($get) === DocumentType::Video),

                        // PDF / Sách
                        FileUpload::make('file_path')
                            ->label(fn (Get $get) => self::type($get) === DocumentType::Book ? 'File sách (PDF / EPUB)' : 'File PDF')
                            ->disk(DocumentStorage::diskName())
                            ->directory(fn (Get $get) => 'files/' . (self::type($get)?->value ?? 'pdf'))
                            ->visibility('private')
                            ->moveFiles()
                            ->acceptedFileTypes(fn (Get $get) => self::type($get) === DocumentType::Book
                                ? ['application/pdf', 'application/epub+zip']
                                : ['application/pdf'])
                            ->maxSize((int) config('ellso.documents.file_max_upload_mb') * 1024)
                            ->storeFileNamesIn('file_name')
                            ->previewable(false)
                            ->downloadable()
                            ->required(fn (Get $get) => in_array(self::type($get), [DocumentType::Pdf, DocumentType::Book], true))
                            ->visible(fn (Get $get) => in_array(self::type($get), [DocumentType::Pdf, DocumentType::Book], true)),
                        Toggle::make('allow_download')
                            ->label('Cho tải về')
                            ->helperText('Học viên có quyền xem thì tải được file sách về máy.')
                            ->default(true)
                            ->visible(fn (Get $get) => self::type($get) === DocumentType::Book),

                        // Sách: đọc thử vài trang / chương đầu (file riêng, ai đăng nhập cũng mở được)
                        Toggle::make('preview_enabled')
                            ->label('Cho đọc thử')
                            ->helperText('Hệ thống cắt phần đầu sách thành một file riêng. Học viên chưa mua vẫn đọc được phần này.')
                            ->default(true)
                            ->live()
                            ->visible(fn (Get $get) => self::type($get) === DocumentType::Book),
                        Grid::make(2)
                            ->visible(fn (Get $get) => self::type($get) === DocumentType::Book && $get('preview_enabled'))
                            ->schema([
                                TextInput::make('preview_pages')
                                    ->label('Số trang đọc thử (PDF)')
                                    ->integer()
                                    ->minValue(1)
                                    ->maxValue(200)
                                    ->default((int) config('ellso.documents.preview_pages'))
                                    ->suffix('trang')
                                    ->required()
                                    ->visible(fn (?Document $record) => $record?->file_format !== 'epub'),
                                TextInput::make('preview_chapters')
                                    ->label('Số chương đọc thử (EPUB)')
                                    ->integer()
                                    ->minValue(1)
                                    ->maxValue(50)
                                    ->default((int) config('ellso.documents.preview_chapters'))
                                    ->suffix('chương')
                                    ->required()
                                    ->helperText('Chương = mục trong danh sách đọc của file EPUB; trang bìa / mục lục cũng có thể tính là một chương.')
                                    ->visible(fn (?Document $record) => $record?->file_format !== 'pdf'),
                            ]),
                        Callout::make(fn (?Document $record) => self::previewStatus($record))
                            ->visible(fn (Get $get, ?Document $record) => self::type($get) === DocumentType::Book && $get('preview_enabled') && $record?->preview_status)
                            ->color(fn (?Document $record) => self::previewColor($record)),
                    ]),

                Section::make('Ai được xem')
                    ->description('Đủ MỘT điều kiện là xem được. Cần chọn ít nhất một điều kiện.')
                    ->schema([
                        Toggle::make('is_free')
                            ->label('Miễn phí — ai đăng nhập cũng xem được')
                            ->live()
                            ->rule(fn (Get $get) => function (string $attribute, mixed $value, Closure $fail) use ($get) {
                                $any = $value || $get('requires_membership') || filled($get('courses')) || filled($get('lessons'))
                                    || (self::type($get) === DocumentType::Book && $get('sell') && filled($get('price')));
                                if (! $any) {
                                    $fail('Chọn ít nhất một điều kiện xem (miễn phí, gói thành viên, khoá học, bài học hoặc bán riêng).');
                                }
                            }),
                        Toggle::make('requires_membership')
                            ->label('Học viên có gói thành viên còn hạn')
                            ->helperText('Gói hết hạn → khoá lại ngay.')
                            ->visible(fn (Get $get) => ! $get('is_free')),
                        Grid::make(2)
                            ->visible(fn (Get $get) => ! $get('is_free'))
                            ->schema([
                                Select::make('courses')
                                    ->label('Theo khoá học')
                                    ->relationship('courses', 'title')
                                    ->multiple()
                                    ->searchable()
                                    ->preload()
                                    ->helperText('Học viên đã được mở khoá học này.'),
                                Select::make('lessons')
                                    ->label('Theo bài học')
                                    ->relationship('lessons', 'title', fn ($query) => $query->library())
                                    ->multiple()
                                    ->searchable()
                                    ->helperText('Bài học đang mở với học viên (theo lộ trình) — không cần học xong.'),
                            ]),
                        Grid::make(2)
                            ->visible(fn (Get $get) => self::type($get) === DocumentType::Book && ! $get('is_free'))
                            ->schema([
                                Toggle::make('sell')
                                    ->label('Bán riêng (mua sách online)')
                                    ->live()
                                    ->afterStateHydrated(fn (Toggle $component, ?Document $record) => $component->state($record?->price !== null)),
                                TextInput::make('price')
                                    ->label('Giá bán')
                                    ->numeric()
                                    ->integer()
                                    ->minValue(0)
                                    ->step(1000)
                                    ->suffix('₫')
                                    ->required(fn (Get $get) => (bool) $get('sell'))
                                    ->visible(fn (Get $get) => (bool) $get('sell')),
                            ]),
                    ]),
            ])->columnSpan(['lg' => 2]),

            Group::make([
                Section::make('Trạng thái')
                    ->schema([
                        ToggleButtons::make('status')
                            ->hiddenLabel()
                            ->options(['draft' => 'Nháp', 'published' => 'Đã xuất bản'])
                            ->colors(['draft' => 'gray', 'published' => 'success'])
                            ->default('draft')
                            ->grouped()
                            ->required()
                            ->helperText('Video chỉ hiện trên app khi đã xử lý xong.'),
                    ]),
                Section::make('Bìa')
                    ->schema([
                        Select::make('cover_color')
                            ->label('Màu bìa')
                            ->options(collect(ChapterColor::cases())->mapWithKeys(fn (ChapterColor $c) => [
                                $c->value => '<span style="display:inline-flex;align-items:center;gap:8px"><span style="width:14px;height:14px;border-radius:4px;background:' . $c->hex() . '"></span>' . e($c->getLabel()) . '</span>',
                            ]))
                            ->allowHtml()
                            ->default(ChapterColor::Teal->value)
                            ->required()
                            ->helperText('App dùng màu này khi không có ảnh bìa.'),
                        FileUpload::make('cover_image')
                            ->label('Ảnh bìa (tuỳ chọn)')
                            ->image()
                            ->imageEditor()
                            ->disk(MediaStorage::diskName())
                            ->directory('documents/covers')
                            ->visibility(MediaStorage::uploadVisibility())
                            ->maxSize(2048)
                            ->helperText(fn (Get $get) => self::type($get) === DocumentType::Book ? 'Sách: nên dùng ảnh dọc tỉ lệ 2:3.' : 'Video / PDF: ảnh ngang 16:9.'),
                    ]),
            ])->columnSpan(['lg' => 1]),
        ]);
    }

    public static function type(Get $get): ?DocumentType
    {
        $type = $get('type');

        return $type instanceof DocumentType ? $type : DocumentType::tryFrom((string) $type);
    }

    /** Trạng thái bản đọc thử: sẵn sàng (N / tổng trang · chương), đang xử lý, lỗi. */
    private static function previewStatus(?Document $record): HtmlString | string
    {
        if (! $record?->preview_status) {
            return '';
        }
        $unit = $record->file_format === 'epub' ? 'chương' : 'trang';
        $wanted = $record->file_format === 'epub' ? $record->preview_chapters : $record->preview_pages;

        return match ($record->preview_status) {
            VideoStatus::Ready => 'Bản đọc thử sẵn sàng · ' . $record->preview_count . ' ' . $unit . ' đầu'
                . ($record->source_count ? ' / ' . $record->source_count . ' ' . $unit : '')
                . ($record->source_count !== null && $record->source_count <= $wanted
                    ? '. Lưu ý: sách chỉ có ' . $record->source_count . ' ' . $unit . ' nên bản đọc thử là toàn bộ sách — nên giảm số ' . $unit . ' đọc thử.'
                    : ''),
            VideoStatus::Failed => new HtmlString('Lỗi tạo bản đọc thử: ' . e((string) $record->preview_error) . '<br>Bấm "Tạo lại bản đọc thử" hoặc tải lại file sách.'),
            default => 'Bản đọc thử: ' . mb_strtolower($record->preview_status->getLabel()) . '… (tải lại trang để xem).',
        };
    }

    private static function previewColor(?Document $record): string
    {
        $wanted = $record?->file_format === 'epub' ? $record?->preview_chapters : $record?->preview_pages;

        return match (true) {
            $record?->preview_status === VideoStatus::Ready && $record->source_count !== null && $record->source_count <= $wanted => 'warning',
            default => $record?->preview_status?->getColor() ?? 'gray',
        };
    }

    private static function videoStatus(?Document $record): HtmlString | string
    {
        if (! $record?->video_status) {
            return '';
        }

        return match ($record->video_status) {
            VideoStatus::Ready => 'Sẵn sàng · ' . $record->durationLabel() . ' · ' . collect($record->hls_variants)->pluck('height')->map(fn ($h) => $h . 'p')->join(', '),
            VideoStatus::Failed => new HtmlString('Lỗi xử lý video: ' . e((string) $record->video_error) . '<br>Tải lại file video rồi lưu để thử lại.'),
            default => $record->video_status->getLabel() . '… trang sẽ cập nhật khi xong (tải lại trang để xem).',
        };
    }
}
