<?php

namespace App\Filament\Resources\PartnerEvents\Schemas;

use App\Models\PartnerEvent;
use App\Support\MediaStorage;
use Closure;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

class PartnerEventForm
{
    private const TZ = 'Asia/Ho_Chi_Minh';

    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(['default' => 1, 'lg' => 3])->components([
            Group::make([
                Section::make('Thông tin sự kiện')
                    ->schema([
                        TextInput::make('title')
                            ->label('Tiêu đề')
                            ->required()
                            ->maxLength(150),
                        TextInput::make('time_text')
                            ->label('Thời gian')
                            ->placeholder('VD: 19:00 – 21:00, thứ Bảy 15/11/2026')
                            ->helperText('Ghi tự do, app hiện đúng như nhập.')
                            ->required()
                            ->maxLength(200),
                        TextInput::make('location')
                            ->label('Địa điểm')
                            ->placeholder('VD: GEM Center, 8 Nguyễn Bỉnh Khiêm, Quận 1 — hoặc: Online qua Zoom')
                            ->required()
                            ->maxLength(300),
                        Textarea::make('description')
                            ->label('Mô tả')
                            ->helperText('Chữ thuần; xuống dòng để tách đoạn.')
                            ->rows(12)
                            ->required()
                            ->maxLength(20000),
                    ]),
            ])->columnSpan(['lg' => 2]),

            Group::make([
                Section::make('Ảnh')
                    ->description('Kích thước cố định ' . PartnerEvent::IMAGE_WIDTH . ' × ' . PartnerEvent::IMAGE_HEIGHT . ' px (tỉ lệ 16:9).')
                    ->schema([
                        FileUpload::make('image_path')
                            ->hiddenLabel()
                            ->image()
                            ->required()
                            ->disk(MediaStorage::diskName())
                            ->directory('partner-events')
                            ->visibility(MediaStorage::uploadVisibility())
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->maxSize(3072)
                            // Cắt + thu về đúng 1280 × 720 ngay trên trình duyệt trước khi tải lên
                            ->imageEditor()
                            ->imageEditorAspectRatioOptions(['16:9'])
                            ->imageCropAspectRatio('16:9')
                            ->imageResizeMode('cover')
                            ->imageResizeTargetWidth((string) PartnerEvent::IMAGE_WIDTH)
                            ->imageResizeTargetHeight((string) PartnerEvent::IMAGE_HEIGHT)
                            ->rule('dimensions:ratio=16/9')
                            ->validationMessages(['dimensions' => 'Ảnh phải có tỉ lệ 16:9 (1280 × 720 px).'])
                            ->helperText('JPG, PNG hoặc WebP, tối đa 3 MB. Ảnh khác tỉ lệ sẽ được cắt về 16:9.'),
                    ]),
                Section::make('Thời gian hiển thị trên app')
                    ->description('Giờ Việt Nam. Sự kiện tự hiện và tự ẩn theo khoảng này.')
                    ->schema([
                        self::dateTime('starts_at', 'Bắt đầu hiển thị')->default(fn () => now(self::TZ)->startOfHour()),
                        self::dateTime('ends_at', 'Kết thúc hiển thị')
                            ->rule(fn (Get $get) => function (string $attribute, mixed $value, Closure $fail) use ($get) {
                                if ($value && $get('starts_at') && Carbon::parse($value)->lte(Carbon::parse($get('starts_at')))) {
                                    $fail('Thời điểm kết thúc phải sau thời điểm bắt đầu.');
                                }
                            }),
                        Toggle::make('is_active')
                            ->label('Hiển thị trên app')
                            ->helperText('Tắt để ẩn ngay mà không cần sửa thời gian.')
                            ->default(true),
                    ]),
            ])->columnSpan(['lg' => 1]),
        ]);
    }

    private static function dateTime(string $name, string $label): DateTimePicker
    {
        return DateTimePicker::make($name)
            ->label($label)
            ->timezone(self::TZ)
            ->native(false)
            ->seconds(false)
            ->displayFormat('H:i d/m/Y')
            ->required();
    }
}
