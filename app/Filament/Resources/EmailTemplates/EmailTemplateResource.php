<?php

namespace App\Filament\Resources\EmailTemplates;

use App\Filament\Resources\EmailTemplates\Pages\EditEmailTemplate;
use App\Filament\Resources\EmailTemplates\Pages\ListEmailTemplates;
use App\Models\EmailTemplate;
use BackedEnum;
use Closure;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Nội dung email. Mẫu do code tạo (migration), admin chỉ sửa văn bản.
 * Logo, mật khẩu tạm, hạn dùng, thông tin yêu cầu và chân trang công ty là phần cố định.
 */
class EmailTemplateResource extends Resource
{
    protected static ?string $model = EmailTemplate::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string | UnitEnum | null $navigationGroup = 'Hệ thống';

    protected static ?int $navigationSort = 8;

    protected static ?string $modelLabel = 'mẫu email';

    protected static ?string $pluralModelLabel = 'Mẫu email';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(fn (?EmailTemplate $record) => $record?->name)
                ->description(fn (?EmailTemplate $record) => $record?->description)
                ->schema([
                    Text::make(fn (?EmailTemplate $record) => 'Biến dùng được: ' . collect($record?->variables() ?? [])
                        ->map(fn ($label, $name) => '{' . $name . '} – ' . $label)
                        ->join(' · ')),
                    Text::make('Viết **chữ** để in đậm. Logo, mật khẩu tạm, hạn dùng, thông tin yêu cầu và chân trang công ty (sửa ở Cấu hình hệ thống) là phần cố định.'),
                ]),

            Section::make('Hộp thư')
                ->description('Những gì người nhận thấy trước khi mở email.')
                ->schema([
                    self::text('subject', 'Tiêu đề email')->required()->maxLength(150)
                        ->helperText('Ngắn gọn, rõ ràng; tránh viết hoa toàn bộ và dấu chấm than để không bị lọc spam.'),
                    self::text('preheader', 'Dòng xem trước')->maxLength(150)
                        ->helperText('Hiện cạnh tiêu đề trong danh sách hộp thư.'),
                ]),

            Section::make('Nội dung')
                ->schema([
                    self::text('eyebrow', 'Nhãn nhỏ phía trên tiêu đề')->maxLength(60),
                    self::text('heading', 'Tiêu đề lớn')->required()->maxLength(120),
                    self::textarea('intro', 'Đoạn mở đầu (phía trên ô mật khẩu)')->required(),
                    self::text('steps_title', 'Tiêu đề các bước')->maxLength(120),
                    Repeater::make('steps')
                        ->label('Các bước (đánh số tự động)')
                        ->simple(self::text('step', 'Bước')->required()->maxLength(300))
                        ->addActionLabel('Thêm bước')
                        ->reorderable()
                        ->defaultItems(0),
                    self::textarea('after_text', 'Đoạn sau ô mật khẩu'),
                    self::textarea('note', 'Ghi chú an toàn (chữ nhỏ)'),
                    self::textarea('sign_off', 'Lời chào cuối'),
                ]),

            Section::make('Chân trang')
                ->schema([
                    self::textarea('footer_reason', 'Lý do nhận email')
                        ->helperText('Câu "Bạn nhận được email này vì…" giúp email đáng tin hơn với bộ lọc spam.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->paginated(false)
            ->columns([
                TextColumn::make('name')
                    ->label('Mẫu email')
                    ->description(fn (EmailTemplate $record) => $record->description)
                    ->wrap(),
                TextColumn::make('subject')->label('Tiêu đề')->wrap(),
                TextColumn::make('updated_at')
                    ->label('Cập nhật')
                    ->since()
                    ->dateTimeTooltip('H:i d/m/Y', 'Asia/Ho_Chi_Minh')
                    ->description(fn (EmailTemplate $record) => $record->updatedBy?->name),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmailTemplates::route('/'),
            'edit' => EditEmailTemplate::route('/{record}/edit'),
        ];
    }

    private static function text(string $name, string $label): TextInput
    {
        return TextInput::make($name)->label($label)->rule(self::knownVariables());
    }

    private static function textarea(string $name, string $label): Textarea
    {
        return Textarea::make($name)->label($label)->rows(3)->autosize()->maxLength(2000)->rule(self::knownVariables());
    }

    /** Báo lỗi khi dùng biến không có trong mẫu (VD gõ nhầm {tne}). */
    private static function knownVariables(): Closure
    {
        return fn (?EmailTemplate $record) => function (string $attribute, mixed $value, Closure $fail) use ($record) {
            if (! $record || ! is_string($value)) {
                return;
            }

            if ($unknown = $record->unknownVariablesIn($value)) {
                $fail('Biến không hợp lệ: ' . collect($unknown)->map(fn ($v) => '{' . $v . '}')->join(', '));
            }
        };
    }
}
