<?php

namespace App\Filament\Resources\MarketingCampaigns\Schemas;

use App\Filament\Support\ContentBlocks;
use App\Models\Course;
use App\Models\MarketingCampaign;
use App\Support\CampaignContent;
use Closure;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use App\Support\MediaStorage;

class MarketingCampaignForm
{
    private const TZ = 'Asia/Ho_Chi_Minh';

    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(['default' => 1, 'lg' => 3])->components([
            Group::make([
                Section::make('Thông tin chiến dịch')
                    ->schema([
                        TextInput::make('title')
                            ->label('Tiêu đề')
                            ->helperText('Hiện ở đầu trang chi tiết; cũng là mô tả ảnh banner cho người dùng trình đọc màn hình.')
                            ->required()
                            ->maxLength(120),
                        Textarea::make('summary')
                            ->label('Mô tả ngắn')
                            ->helperText('1–2 câu ngay dưới tiêu đề. VD: Giảm 30% gói thành viên cho 200 học viên đầu tiên.')
                            ->rows(2)
                            ->maxLength(300),
                    ]),
                Section::make('Nội dung chi tiết')
                    ->description('Học viên bấm vào banner sẽ thấy trang này. Thêm, sắp xếp các khối: đoạn văn, cách áp dụng, điều kiện, mã khuyến mãi, lưu ý…')
                    ->schema([
                        Builder::make('content')
                            ->hiddenLabel()
                            ->default(CampaignContent::starter())
                            ->addActionLabel('Thêm khối nội dung')
                            ->collapsible()
                            ->cloneable()
                            ->blockNumbers(false)
                            ->blocks(ContentBlocks::make('campaigns/content', withPromoCode: true)),
                    ]),
            ])->columnSpan(['lg' => 2]),

            Group::make([
                Section::make('Banner')
                    ->description('Kích thước cố định ' . MarketingCampaign::BANNER_WIDTH . ' × ' . MarketingCampaign::BANNER_HEIGHT . ' px (tỉ lệ 16:9).')
                    ->schema([
                        FileUpload::make('banner_path')
                            ->hiddenLabel()
                            ->image()
                            ->required()
                            ->disk(MediaStorage::diskName())
                            ->directory('campaigns/banners')
                            ->visibility(MediaStorage::uploadVisibility())
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->maxSize(3072)
                            // Cắt + thu về đúng 1280 × 720 ngay trên trình duyệt trước khi tải lên
                            ->imageEditor()
                            ->imageEditorAspectRatioOptions(['16:9'])
                            ->imageCropAspectRatio('16:9')
                            ->imageResizeMode('cover')
                            ->imageResizeTargetWidth((string) MarketingCampaign::BANNER_WIDTH)
                            ->imageResizeTargetHeight((string) MarketingCampaign::BANNER_HEIGHT)
                            ->rule('dimensions:ratio=16/9')
                            ->validationMessages(['dimensions' => 'Banner phải có tỉ lệ 16:9 (1280 × 720 px).'])
                            ->helperText('JPG, PNG hoặc WebP, tối đa 3 MB. Ảnh khác tỉ lệ sẽ được cắt về 16:9. Giữ chữ và logo cách mép ít nhất 60 px vì thẻ trên app bo tròn góc. Nên thiết kế ở 1280 × 720 để ảnh sắc nét.'),
                    ]),
                Section::make('Thời gian hiển thị')
                    ->description('Giờ Việt Nam. Banner tự hiện và tự ẩn theo khoảng này.')
                    ->schema([
                        self::dateTime('starts_at', 'Bắt đầu')->default(fn () => now(self::TZ)->startOfHour()),
                        self::dateTime('ends_at', 'Kết thúc')
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
                Section::make('Nút cuối trang')
                    ->schema([
                        ToggleButtons::make('cta_type')
                            ->hiddenLabel()
                            ->options([
                                MarketingCampaign::CTA_NONE => 'Không có',
                                MarketingCampaign::CTA_COURSE => 'Mở khoá học',
                                MarketingCampaign::CTA_URL => 'Mở liên kết',
                            ])
                            ->default(MarketingCampaign::CTA_NONE)
                            ->grouped()
                            ->live(),
                        TextInput::make('cta_label')
                            ->label('Chữ trên nút')
                            ->placeholder('VD: Đăng ký ngay')
                            ->maxLength(40)
                            ->required(fn (Get $get) => $get('cta_type') !== MarketingCampaign::CTA_NONE)
                            ->visible(fn (Get $get) => $get('cta_type') !== MarketingCampaign::CTA_NONE),
                        Select::make('cta_course_id')
                            ->label('Khoá học')
                            ->options(fn () => Course::query()->orderBy('title')->pluck('title', 'id')->all())
                            ->searchable()
                            ->required(fn (Get $get) => $get('cta_type') === MarketingCampaign::CTA_COURSE)
                            ->visible(fn (Get $get) => $get('cta_type') === MarketingCampaign::CTA_COURSE),
                        TextInput::make('cta_url')
                            ->label('Liên kết')
                            ->placeholder('https://')
                            ->url()
                            ->maxLength(500)
                            ->required(fn (Get $get) => $get('cta_type') === MarketingCampaign::CTA_URL)
                            ->visible(fn (Get $get) => $get('cta_type') === MarketingCampaign::CTA_URL),
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
