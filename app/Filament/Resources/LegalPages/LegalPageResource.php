<?php

namespace App\Filament\Resources\LegalPages;

use App\Filament\Resources\LegalPages\Pages\EditLegalPage;
use App\Filament\Resources\LegalPages\Pages\ListLegalPages;
use App\Models\LegalPage;
use BackedEnum;
use Closure;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Điều khoản sử dụng + Chính sách bảo mật hiển thị trong app (màn Đăng ký, Hồ sơ › Điều khoản & quyền riêng tư).
 * 2 trang cố định, admin sửa tiêu đề, phiên bản, ngày hiệu lực và nội dung Markdown.
 */
class LegalPageResource extends Resource
{
    protected static ?string $model = LegalPage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Hệ thống';

    protected static ?string $navigationLabel = 'Điều khoản & bảo mật';

    protected static ?int $navigationSort = 9;

    protected static ?string $modelLabel = 'trang pháp lý';

    protected static ?string $pluralModelLabel = 'Điều khoản & chính sách bảo mật';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Thông tin')
                ->columns(3)
                ->schema([
                    TextInput::make('title')->label('Tiêu đề')->required()->maxLength(120)->columnSpan(1),
                    TextInput::make('version')->label('Phiên bản')->required()->maxLength(20)
                        ->helperText('Tăng khi sửa nội dung quan trọng (VD 1.1, 2.0).'),
                    DatePicker::make('effective_on')->label('Ngày hiệu lực')->required()->native(false)->displayFormat('d/m/Y'),
                ]),
            Section::make('Nội dung')
                ->description('Biến dùng được (lấy từ Cấu hình hệ thống): '.collect(LegalPage::VARIABLE_LABELS)
                    ->map(fn ($label, $name) => '{'.$name.'} – '.$label)->join(' · ')
                    .'. Định dạng: ## Tiêu đề mục, ### Tiêu đề nhỏ, - gạch đầu dòng, 1. đánh số, **đậm**, *nghiêng*, [chữ](https://link). Dòng trống để tách đoạn.')
                ->schema([
                    MarkdownEditor::make('content')
                        ->hiddenLabel()
                        ->required()
                        ->toolbarButtons([['bold', 'italic', 'link'], ['heading', 'bulletList', 'orderedList'], ['undo', 'redo']])
                        ->minHeight('32rem')
                        ->rule(fn () => function (string $attribute, mixed $value, Closure $fail) {
                            if (is_string($value) && ($unknown = LegalPage::unknownVariablesIn($value))) {
                                $fail('Biến không hợp lệ: '.collect($unknown)->map(fn ($v) => '{'.$v.'}')->join(', '));
                            }
                        }),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->paginated(false)
            ->defaultSort('id')
            ->columns([
                TextColumn::make('title')->label('Trang')->weight('medium')->description('App: màn Đăng ký và Hồ sơ › Điều khoản & quyền riêng tư'),
                TextColumn::make('version')->label('Phiên bản')->badge()->color('gray'),
                TextColumn::make('effective_on')->label('Hiệu lực từ')->date('d/m/Y'),
                TextColumn::make('updated_at')
                    ->label('Cập nhật')
                    ->since()
                    ->dateTimeTooltip('H:i d/m/Y', 'Asia/Ho_Chi_Minh')
                    ->description(fn (LegalPage $record) => $record->updatedBy?->name),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLegalPages::route('/'),
            'edit' => EditLegalPage::route('/{record}/edit'),
        ];
    }
}
