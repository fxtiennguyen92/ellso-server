<?php

namespace App\Filament\Resources\MarketingCampaigns\Schemas;

use App\Models\Course;
use App\Models\MarketingCampaign;
use App\Models\PromoCode;
use App\Support\CampaignContent;
use Closure;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class MarketingCampaignForm
{
    private const TZ = 'Asia/Ho_Chi_Minh';

    private const TEXT_HELP = 'Viết **chữ** để in đậm. Xuống dòng để tách đoạn.';

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
                            ->blocks(self::blocks()),
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
                            ->disk('public')
                            ->directory('campaigns/banners')
                            ->visibility('public')
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

    /** @return list<Block> */
    private static function blocks(): array
    {
        $excerpt = fn (string $label, ?string $text) => filled($text) ? $label . ': ' . Str::limit(strip_tags($text), 60) : $label;

        return [
            Block::make('paragraph')
                ->label(fn (?array $state) => $excerpt('Đoạn văn', $state['text'] ?? null))
                ->icon(Heroicon::Bars3BottomLeft)
                ->schema([
                    Textarea::make('text')->hiddenLabel()->rows(3)->autosize()->maxLength(3000)->helperText(self::TEXT_HELP),
                ]),
            Block::make('heading')
                ->label(fn (?array $state) => $excerpt('Tiêu đề mục', $state['text'] ?? null))
                ->icon(Heroicon::H2)
                ->schema([
                    TextInput::make('text')->hiddenLabel()->maxLength(120),
                ]),
            Block::make('steps')
                ->label(fn (?array $state) => $excerpt('Các bước', $state['title'] ?? null))
                ->icon(Heroicon::NumberedList)
                ->schema([
                    TextInput::make('title')->label('Tiêu đề')->placeholder('Cách áp dụng')->maxLength(120),
                    Repeater::make('items')
                        ->label('Các bước (đánh số tự động)')
                        ->simple(TextInput::make('item')->maxLength(300))
                        ->addActionLabel('Thêm bước')
                        ->defaultItems(0),
                ]),
            Block::make('bullets')
                ->label(fn (?array $state) => $excerpt('Danh sách', $state['title'] ?? null))
                ->icon(Heroicon::ListBullet)
                ->schema([
                    TextInput::make('title')->label('Tiêu đề')->placeholder('Điều kiện áp dụng / Quyền lợi')->maxLength(120),
                    Repeater::make('items')
                        ->label('Các ý')
                        ->simple(TextInput::make('item')->maxLength(300))
                        ->addActionLabel('Thêm ý')
                        ->defaultItems(0),
                ]),
            Block::make('promo_code')
                ->label(fn (?array $state) => 'Mã khuyến mãi' . (filled($state['promo_code_id'] ?? null) ? ': ' . PromoCode::find($state['promo_code_id'])?->code : ''))
                ->icon(Heroicon::Ticket)
                ->schema([
                    Select::make('promo_code_id')
                        ->label('Mã')
                        ->helperText('App hiện ô mã kèm nút Sao chép và mức giảm.')
                        ->options(fn () => PromoCode::query()->latest('id')->get()
                            ->mapWithKeys(fn (PromoCode $p) => [$p->id => $p->code . ' · ' . $p->discountSummary()])->all())
                        ->searchable()
                        ->required(),
                    TextInput::make('note')->label('Ghi chú dưới mã')->placeholder('VD: Nhập mã ở bước thanh toán')->maxLength(200),
                ]),
            Block::make('image')
                ->label(fn (?array $state) => $excerpt('Ảnh', $state['caption'] ?? null))
                ->icon(Heroicon::Photo)
                ->schema([
                    FileUpload::make('image')
                        ->hiddenLabel()
                        ->image()
                        ->imageEditor()
                        ->disk('public')
                        ->directory('campaigns/content')
                        ->visibility('public')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(3072)
                        ->required(),
                    TextInput::make('caption')->label('Chú thích')->maxLength(200),
                ]),
            Block::make('callout')
                ->label(fn (?array $state) => $excerpt('Khung lưu ý', $state['title'] ?? $state['text'] ?? null))
                ->icon(Heroicon::InformationCircle)
                ->schema([
                    Grid::make(['default' => 1, 'md' => 3])->schema([
                        ToggleButtons::make('tone')
                            ->label('Kiểu')
                            ->options(['info' => 'Thông tin', 'warning' => 'Chú ý'])
                            ->default('info')
                            ->grouped(),
                        TextInput::make('title')->label('Tiêu đề')->maxLength(120)->columnSpan(['md' => 2]),
                    ]),
                    Textarea::make('text')->label('Nội dung')->rows(2)->autosize()->maxLength(1000)->helperText(self::TEXT_HELP),
                ]),
        ];
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
