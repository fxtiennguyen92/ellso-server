<?php

namespace App\Filament\Resources\TrainingCourses\Schemas;

use App\Enums\TrainingCourseStatus;
use App\Enums\UserRole;
use App\Filament\Support\BannerUpload;
use App\Filament\Support\ContentBlocks;
use App\Models\TrainingCourse;
use App\Support\MediaStorage;
use App\Support\YouTube;
use Closure;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class TrainingCourseForm
{
    public static function configure(Schema $schema): Schema
    {
        $trailerMb = (int) config('ellso.trainings.trailer_max_upload_mb');

        return $schema->columns(['default' => 1, 'lg' => 3])->components([
            Group::make([
                Section::make('Thông tin chương trình')
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')
                            ->label('Tên chương trình')
                            ->placeholder('VD: Kỹ năng quản lý đội nhóm')
                            ->required()
                            ->maxLength(150)
                            ->columnSpanFull(),
                        Textarea::make('summary')
                            ->label('Mô tả ngắn')
                            ->helperText('1–2 câu hiện dưới tên chương trình trên app.')
                            ->rows(2)
                            ->maxLength(300)
                            ->columnSpanFull(),
                        TextInput::make('price')
                            ->label('Phí tham gia')
                            ->numeric()
                            ->minValue(0)
                            ->step(1000)
                            ->suffix('₫')
                            ->default(0)
                            ->helperText('Cho cả chương trình. 0 = miễn phí với học viên có gói thành viên. Học viên luôn cần gói thành viên còn hạn mới đăng ký được.')
                            ->required(),
                        TextInput::make('capacity')
                            ->label('Số học viên tối đa')
                            ->integer()
                            ->minValue(1)
                            ->suffix('người')
                            ->default(20)
                            ->helperText('Cho cả chương trình (học viên đăng ký tham gia tất cả các buổi).')
                            ->required(),
                        Select::make('trainer_id')
                            ->label('Người hướng dẫn')
                            ->relationship('trainer', 'name', fn ($query) => $query
                                ->where('role', UserRole::Instructor)
                                ->where('is_active', true))
                            ->searchable()
                            ->preload()
                            ->required()
                            ->helperText('Tài khoản Giảng viên. Giảng viên đăng nhập CMS sẽ thấy chương trình mình phụ trách.')
                            ->columnSpanFull(),
                    ]),
                Section::make('Video trailer')
                    ->description('Không bắt buộc. Tải video lên (phát trực tiếp, không chuyển đổi) hoặc dán link YouTube.')
                    ->schema([
                        ToggleButtons::make('trailer_type')
                            ->hiddenLabel()
                            ->options(['none' => 'Không có', 'file' => 'Tải video lên', 'youtube' => 'Link YouTube'])
                            ->icons(['none' => Heroicon::NoSymbol, 'file' => Heroicon::ArrowUpTray, 'youtube' => Heroicon::PlayCircle])
                            ->grouped()
                            ->default('none')
                            ->live()
                            ->afterStateHydrated(fn (ToggleButtons $component, ?TrainingCourse $record) => $component->state(match (true) {
                                filled($record?->trailer_video_path) => 'file',
                                filled($record?->trailer_youtube_url) => 'youtube',
                                default => 'none',
                            }))
                            ->dehydrated(false),
                        FileUpload::make('trailer_video_path')
                            ->label('File video')
                            ->disk(MediaStorage::diskName())
                            ->directory('trainings/trailers')
                            ->visibility(MediaStorage::uploadVisibility())
                            ->acceptedFileTypes(['video/mp4', 'video/webm', 'video/quicktime'])
                            ->maxSize($trailerMb * 1024)
                            ->required(fn (Get $get) => $get('trailer_type') === 'file')
                            ->visible(fn (Get $get) => $get('trailer_type') === 'file')
                            ->helperText("MP4, WebM hoặc MOV, tối đa {$trailerMb} MB. Nên dùng MP4 (H.264) 720p, dài 1–2 phút để app tải nhanh."),
                        TextInput::make('trailer_youtube_url')
                            ->label('Link YouTube')
                            ->placeholder('https://www.youtube.com/watch?v=…')
                            ->url()
                            ->maxLength(300)
                            ->required(fn (Get $get) => $get('trailer_type') === 'youtube')
                            ->visible(fn (Get $get) => $get('trailer_type') === 'youtube')
                            ->rule(fn () => function (string $attribute, mixed $value, Closure $fail) {
                                if (filled($value) && ! YouTube::isValid($value)) {
                                    $fail('Link YouTube không hợp lệ (VD: https://www.youtube.com/watch?v=… hoặc https://youtu.be/…).');
                                }
                            }),
                    ]),
                Section::make('Nội dung chi tiết')
                    ->description('Trang chi tiết chương trình trên app. Thêm, sắp xếp các khối: đoạn văn, nội dung các buổi, đối tượng phù hợp, ảnh, lưu ý…')
                    ->schema([
                        Builder::make('content')
                            ->hiddenLabel()
                            ->default([
                                ['type' => 'paragraph', 'data' => ['text' => '']],
                                ['type' => 'bullets', 'data' => ['title' => 'Bạn sẽ học được', 'items' => []]],
                                ['type' => 'bullets', 'data' => ['title' => 'Phù hợp với', 'items' => []]],
                            ])
                            ->addActionLabel('Thêm khối nội dung')
                            ->collapsible()
                            ->cloneable()
                            ->blockNumbers(false)
                            ->blocks(ContentBlocks::make('trainings/content')),
                    ]),
            ])->columnSpan(['lg' => 2]),

            Group::make([
                Section::make('Trạng thái')
                    ->schema([
                        ToggleButtons::make('status')
                            ->hiddenLabel()
                            ->options(TrainingCourseStatus::class)
                            ->default(TrainingCourseStatus::Draft)
                            ->grouped()
                            ->required()
                            ->helperText('"Đang mở": hiện trên app khi còn ít nhất một buổi sắp diễn ra. Học viên đăng ký được đến trước giờ bắt đầu buổi 1.'),
                    ]),
                Section::make('Banner')
                    ->description(BannerUpload::description())
                    ->schema([BannerUpload::make('trainings/banners')]),
            ])->columnSpan(['lg' => 1]),
        ]);
    }

    /**
     * Chỉ giữ một loại trailer: bỏ file khi chọn YouTube / "Không có" và ngược lại.
     * (Ô bị ẩn không gửi giá trị → phải xoá tay; file cũ bị xoá khỏi storage nhờ CleansUpFiles.)
     */
    public static function normalizeTrailer(array $data, string $type): array
    {
        if ($type !== 'file') {
            $data['trailer_video_path'] = null;
        }
        if ($type !== 'youtube') {
            $data['trailer_youtube_url'] = null;
        }

        return $data;
    }
}
