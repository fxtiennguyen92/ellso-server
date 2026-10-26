<?php

namespace App\Filament\Resources\TrainingCourses\Schemas;

use App\Enums\TrainingCourseStatus;
use App\Filament\Support\ContentBlocks;
use App\Models\TrainingCourse;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TrainingCourseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(['default' => 1, 'lg' => 3])->components([
            Group::make([
                Section::make('Thông tin khoá huấn luyện')
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')
                            ->label('Tên khoá')
                            ->placeholder('VD: Kỹ năng quản lý đội nhóm')
                            ->required()
                            ->maxLength(150)
                            ->columnSpanFull(),
                        Textarea::make('summary')
                            ->label('Mô tả ngắn')
                            ->helperText('1–2 câu hiện dưới tên khoá trên app.')
                            ->rows(2)
                            ->maxLength(300)
                            ->columnSpanFull(),
                        TextInput::make('price')
                            ->label('Giá mỗi người')
                            ->numeric()
                            ->minValue(0)
                            ->step(1000)
                            ->suffix('₫')
                            ->default(0)
                            ->helperText('Giá theo khoá, ngày nào cũng như nhau. 0 = miễn phí.')
                            ->required(),
                        TextInput::make('trainer_name')
                            ->label('Người hướng dẫn')
                            ->placeholder('VD: ThS. Nguyễn Văn A')
                            ->maxLength(120),
                    ]),
                Section::make('Nội dung chi tiết')
                    ->description('Trang chi tiết khoá trên app. Thêm, sắp xếp các khối: đoạn văn, nội dung buổi học, đối tượng phù hợp, ảnh, lưu ý…')
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
                            ->helperText('"Đang mở": hiện trên app khi còn ít nhất một ngày sắp diễn ra.'),
                    ]),
                Section::make('Banner')
                    ->description('Kích thước cố định ' . TrainingCourse::BANNER_WIDTH . ' × ' . TrainingCourse::BANNER_HEIGHT . ' px (tỉ lệ 16:9).')
                    ->schema([
                        FileUpload::make('banner_path')
                            ->hiddenLabel()
                            ->image()
                            ->required()
                            ->disk('public')
                            ->directory('trainings/banners')
                            ->visibility('public')
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->maxSize(3072)
                            ->imageEditor()
                            ->imageEditorAspectRatioOptions(['16:9'])
                            ->imageCropAspectRatio('16:9')
                            ->imageResizeMode('cover')
                            ->imageResizeTargetWidth((string) TrainingCourse::BANNER_WIDTH)
                            ->imageResizeTargetHeight((string) TrainingCourse::BANNER_HEIGHT)
                            ->rule('dimensions:ratio=16/9')
                            ->validationMessages(['dimensions' => 'Banner phải có tỉ lệ 16:9 (1280 × 720 px).'])
                            ->helperText('JPG, PNG hoặc WebP, tối đa 3 MB. Ảnh khác tỉ lệ sẽ được cắt về 16:9.'),
                    ]),
            ])->columnSpan(['lg' => 1]),
        ]);
    }
}
