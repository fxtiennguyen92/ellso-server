<?php

namespace App\Filament\Resources\Workshops\Schemas;

use App\Enums\TrainingCourseStatus;
use App\Enums\UserRole;
use App\Filament\Support\BannerUpload;
use App\Filament\Support\ContentBlocks;
use App\Models\Workshop;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class WorkshopForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(['default' => 1, 'lg' => 3])->components([
            Group::make([
                Section::make('Thông tin workshop')
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')
                            ->label('Tên workshop')
                            ->placeholder('VD: Giao tiếp tự tin khi thuyết trình')
                            ->required()
                            ->maxLength(150)
                            ->columnSpanFull(),
                        Textarea::make('summary')
                            ->label('Mô tả ngắn')
                            ->helperText('1–2 câu hiện dưới tên workshop trên app.')
                            ->rows(2)
                            ->maxLength(300)
                            ->columnSpanFull(),
                        TextInput::make('price')
                            ->label('Phí tham gia mỗi buổi')
                            ->numeric()
                            ->minValue(0)
                            ->step(1000)
                            ->suffix('₫')
                            ->default(0)
                            ->helperText('0 = miễn phí với học viên có gói thành viên. Học viên luôn cần gói thành viên còn hạn mới đăng ký được.')
                            ->required(),
                        Select::make('trainer_id')
                            ->label('Người hướng dẫn')
                            ->relationship('trainer', 'name', fn ($query) => $query
                                ->where('role', UserRole::Instructor)
                                ->where('is_active', true))
                            ->searchable()
                            ->preload()
                            ->required()
                            ->helperText('Tài khoản Giảng viên. Giảng viên đăng nhập CMS sẽ thấy workshop mình phụ trách.'),
                        Toggle::make('limit_capacity')
                            ->label('Giới hạn số người mỗi buổi')
                            ->live()
                            ->afterStateHydrated(fn (Toggle $component, ?Workshop $record) => $component->state($record?->capacity !== null))
                            ->dehydrated(false)
                            ->inline(false),
                        TextInput::make('capacity')
                            ->label('Tối đa mỗi buổi')
                            ->integer()
                            ->minValue(1)
                            ->suffix('người')
                            ->required(fn (Get $get) => (bool) $get('limit_capacity'))
                            ->visible(fn (Get $get) => (bool) $get('limit_capacity'))
                            ->helperText('Áp dụng cho từng buổi (từng ngày / khung giờ).'),
                    ]),
                Section::make('Nội dung chi tiết')
                    ->description('Trang chi tiết workshop trên app. Thêm, sắp xếp các khối: đoạn văn, nội dung, đối tượng phù hợp, ảnh, lưu ý…')
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
                            ->blocks(ContentBlocks::make('workshops/content')),
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
                            ->helperText('"Đang mở": hiện trên app khi còn ít nhất một buổi sắp diễn ra.'),
                    ]),
                Section::make('Banner')
                    ->description(BannerUpload::description())
                    ->schema([BannerUpload::make('workshops/banners')]),
            ])->columnSpan(['lg' => 1]),
        ]);
    }

    /** Tắt "Giới hạn số người" → không giới hạn (capacity null). */
    public static function normalizeCapacity(array $data, bool $limited): array
    {
        if (! $limited) {
            $data['capacity'] = null;
        }

        return $data;
    }
}
