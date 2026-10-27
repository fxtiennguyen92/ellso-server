<?php

namespace App\Filament\Resources\Lessons\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use App\Support\MediaStorage;

/** Thông tin bài học (tên, icon, tóm tắt). Dùng ở thư viện bài học và hộp thoại "Tạo bài mới". */
class LessonForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components(self::fields());
    }

    /** @return array<\Filament\Schemas\Components\Component> */
    public static function fields(): array
    {
        return [
            TextInput::make('title')
                ->label('Tên bài học')
                ->required()
                ->maxLength(255),
            self::iconField(),
            Textarea::make('summary')
                ->label('Tóm tắt (hiện trên app)')
                ->rows(2)
                ->maxLength(500),
        ];
    }

    public static function iconField(): FileUpload
    {
        return FileUpload::make('icon')
            ->label('Icon')
            ->helperText('SVG hoặc PNG nền trong suốt, hình vuông, nên là màu trắng (nền icon là màu của chương). Tối đa 1 MB.')
            ->disk(MediaStorage::diskName())
            ->directory('lessons/icons')
            ->acceptedFileTypes(['image/svg+xml', 'image/png', 'image/webp'])
            ->maxSize(1024)
            ->imagePreviewHeight('96');
    }
}
