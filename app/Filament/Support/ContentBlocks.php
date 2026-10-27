<?php

namespace App\Filament\Support;

use App\Models\PromoCode;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use App\Support\MediaStorage;

/**
 * Các khối nội dung (Filament Builder) dùng chung cho trang chi tiết Chiến dịch và Khoá huấn luyện.
 * Dữ liệu chuẩn hoá cho app bằng App\Support\CampaignContent::resolve().
 */
class ContentBlocks
{
    private const TEXT_HELP = 'Viết **chữ** để in đậm. Xuống dòng để tách đoạn.';

    /**
     * @return list<Block>
     */
    public static function make(string $imageDirectory, bool $withPromoCode = false): array
    {
        $excerpt = fn (string $label, ?string $text) => filled($text) ? $label . ': ' . Str::limit(strip_tags($text), 60) : $label;

        return array_values(array_filter([
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
            $withPromoCode ? Block::make('promo_code')
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
                ]) : null,
            Block::make('image')
                ->label(fn (?array $state) => $excerpt('Ảnh', $state['caption'] ?? null))
                ->icon(Heroicon::Photo)
                ->schema([
                    FileUpload::make('image')
                        ->hiddenLabel()
                        ->image()
                        ->imageEditor()
                        ->disk(MediaStorage::diskName())
                        ->directory($imageDirectory)
                        ->visibility(MediaStorage::uploadVisibility())
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
        ]));
    }
}
