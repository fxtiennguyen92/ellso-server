<?php

namespace App\Filament\Resources\PromoCodes\Schemas;

use App\Enums\DiscountType;
use App\Models\PromoCode;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Support\RawJs;
use Illuminate\Support\Carbon;

class PromoCodeForm
{
    private const TZ = 'Asia/Ho_Chi_Minh';

    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Text::make(fn (?PromoCode $record) => self::usageSummary($record))
                ->visible(fn (?PromoCode $record) => $record !== null),

            Section::make('Mã khuyến mãi')
                ->columns(2)
                ->schema([
                    TextInput::make('code')
                        ->label('Mã')
                        ->required()
                        ->default(fn () => PromoCode::generateUniqueCode())
                        ->maxLength(10)
                        ->extraInputAttributes(['style' => 'text-transform: uppercase; font-family: ui-monospace, monospace; letter-spacing: 0.05em'])
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (?string $state, Set $set) => $set('code', PromoCode::normalize($state)))
                        ->dehydrateStateUsing(fn (?string $state) => PromoCode::normalize($state))
                        ->rule(fn (?PromoCode $record) => self::codeRule($record))
                        ->helperText(fn (?PromoCode $record) => $record?->used_count
                            ? 'Mã đã được dùng nên không đổi được.'
                            : '8–10 ký tự, chỉ chữ in hoa và số. Bấm ↻ để tạo mã ngẫu nhiên.')
                        ->disabled(fn (?PromoCode $record) => (bool) $record?->used_count)
                        ->suffixAction(
                            Action::make('generateCode')
                                ->icon(Heroicon::ArrowPath)
                                ->tooltip('Tạo mã ngẫu nhiên')
                                ->visible(fn (?PromoCode $record) => ! $record?->used_count)
                                ->action(fn (Set $set) => $set('code', PromoCode::generateUniqueCode())),
                        ),
                    TextInput::make('name')
                        ->label('Tên chương trình')
                        ->helperText('Chỉ hiển thị nội bộ. VD: Khuyến mãi khai giảng tháng 11.')
                        ->required()
                        ->maxLength(255),
                    Textarea::make('description')
                        ->label('Mô tả cho học viên')
                        ->helperText('Hiện trong app khi học viên nhập mã. VD: Giảm 5% cho mọi khoá học, tối đa 500.000 ₫.')
                        ->rows(2)
                        ->maxLength(500)
                        ->columnSpanFull(),
                    Toggle::make('is_active')
                        ->label('Đang bật')
                        ->helperText('Tắt để tạm dừng mã ngay mà không cần xoá.')
                        ->default(true),
                ]),

            Section::make('Mức giảm')
                ->columns(2)
                ->schema([
                    ToggleButtons::make('discount_type')
                        ->label('Kiểu giảm')
                        ->options(DiscountType::class)
                        ->default(DiscountType::Percent)
                        ->inline()
                        ->required()
                        ->live()
                        ->columnSpanFull(),
                    TextInput::make('discount_value')
                        ->label(fn (Get $get) => self::isPercent($get) ? 'Tỉ lệ giảm' : 'Số tiền giảm')
                        ->required()
                        ->suffix(fn (Get $get) => self::isPercent($get) ? '%' : '₫')
                        ->mask(fn (Get $get) => self::isPercent($get) ? null : self::moneyMask())
                        ->stripCharacters(fn (Get $get) => self::isPercent($get) ? null : '.')
                        ->numeric()
                        ->minValue(fn (Get $get) => self::isPercent($get) ? 0.01 : 1)
                        ->maxValue(fn (Get $get) => self::isPercent($get) ? 100 : null)
                        ->integer(fn (Get $get) => ! self::isPercent($get)),
                    self::money('max_discount', 'Giảm tối đa')
                        ->helperText('Để trống nếu không giới hạn. VD: 5% nhưng tối đa 500.000 ₫.')
                        ->visible(fn (Get $get) => self::isPercent($get)),
                    self::money('min_order_amount', 'Giá trị đơn tối thiểu')
                        ->helperText('Để trống nếu không yêu cầu.'),
                ]),

            Section::make('Thời gian áp dụng')
                ->description('Giờ Việt Nam. Để trống nếu không giới hạn.')
                ->columns(2)
                ->schema([
                    self::dateTime('starts_at', 'Bắt đầu'),
                    self::dateTime('ends_at', 'Kết thúc')
                        ->rule(fn (Get $get) => function (string $attribute, mixed $value, Closure $fail) use ($get) {
                            $start = $get('starts_at');
                            if ($value && $start && Carbon::parse($value)->lte(Carbon::parse($start))) {
                                $fail('Thời điểm kết thúc phải sau thời điểm bắt đầu.');
                            }
                        }),
                ]),

            Section::make('Giới hạn lượt dùng')
                ->columns(2)
                ->schema([
                    TextInput::make('usage_limit')
                        ->label('Tổng số lượt')
                        ->helperText('Toàn hệ thống. VD: 100 người đầu tiên. Để trống nếu không giới hạn.')
                        ->numeric()
                        ->integer()
                        ->minValue(1),
                    TextInput::make('per_user_limit')
                        ->label('Mỗi học viên tối đa')
                        ->helperText('Để trống nếu không giới hạn.')
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->default(1)
                        ->suffix('lần'),
                ]),

            Section::make('Phạm vi áp dụng')
                ->schema([
                    Radio::make('applies_to')
                        ->hiddenLabel()
                        ->options([
                            PromoCode::APPLIES_ALL => 'Tất cả khoá học',
                            PromoCode::APPLIES_SELECTED => 'Chỉ khoá học / danh mục được chọn',
                        ])
                        ->default(PromoCode::APPLIES_ALL)
                        ->required()
                        ->live(),
                    Grid::make(2)
                        ->visible(fn (Get $get) => $get('applies_to') === PromoCode::APPLIES_SELECTED)
                        ->schema([
                            Select::make('courses')
                                ->label('Khoá học')
                                ->relationship('courses', 'title')
                                ->multiple()
                                ->searchable()
                                ->preload()
                                ->rule(fn (Get $get) => function (string $attribute, mixed $value, Closure $fail) use ($get) {
                                    if ($get('applies_to') === PromoCode::APPLIES_SELECTED
                                        && blank($value) && blank($get('categories'))) {
                                        $fail('Chọn ít nhất một khoá học hoặc danh mục.');
                                    }
                                }),
                            Select::make('categories')
                                ->label('Danh mục')
                                ->helperText('Gồm cả khoá học thuộc danh mục con.')
                                ->relationship('categories', 'name')
                                ->multiple()
                                ->searchable()
                                ->preload(),
                        ]),
                ]),
        ]);
    }

    private static function isPercent(Get $get): bool
    {
        $type = $get('discount_type');

        return ($type instanceof DiscountType ? $type : DiscountType::tryFrom((string) $type)) !== DiscountType::Fixed;
    }

    private static function moneyMask(): RawJs
    {
        return RawJs::make('$money($input, \',\', \'.\', 0)');
    }

    private static function money(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->suffix('₫')
            ->mask(self::moneyMask())
            ->stripCharacters('.')
            ->numeric()
            ->integer()
            ->minValue(1);
    }

    private static function dateTime(string $name, string $label): DateTimePicker
    {
        return DateTimePicker::make($name)
            ->label($label)
            ->timezone(self::TZ)
            ->native(false)
            ->seconds(false)
            ->displayFormat('H:i d/m/Y');
    }

    private static function codeRule(?PromoCode $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($record) {
            $code = PromoCode::normalize($value);

            if (! preg_match(PromoCode::CODE_PATTERN, $code)) {
                $fail('Mã gồm 8–10 ký tự, chỉ chữ in hoa (A–Z) và số.');

                return;
            }

            $taken = PromoCode::withTrashed()
                ->where('code', $code)
                ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))
                ->exists();

            if ($taken) {
                $fail('Mã này đã tồn tại (kể cả mã đã xoá).');
            }
        };
    }

    private static function usageSummary(?PromoCode $record): string
    {
        if (! $record) {
            return '';
        }

        $discounted = (int) $record->redemptions()->sum('discount_amount');

        return 'Trạng thái: ' . $record->status()->getLabel()
            . ' · Đã dùng ' . $record->used_count . ($record->usage_limit ? '/' . $record->usage_limit : '') . ' lượt'
            . ' · Tổng tiền đã giảm: ' . PromoCode::money($discounted);
    }
}
