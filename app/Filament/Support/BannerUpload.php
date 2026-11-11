<?php

namespace App\Filament\Support;

use App\Models\MarketingCampaign;
use App\Support\MediaStorage;
use Filament\Forms\Components\FileUpload;

/** Ô tải banner 16:9 (1280 × 720) dùng chung: chương trình huấn luyện, workshop. Ảnh khác tỉ lệ được cắt về 16:9. */
class BannerUpload
{
    public static function make(string $directory): FileUpload
    {
        return FileUpload::make('banner_path')
            ->hiddenLabel()
            ->image()
            ->required()
            ->disk(MediaStorage::diskName())
            ->directory($directory)
            ->visibility(MediaStorage::uploadVisibility())
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->maxSize(3072)
            ->imageEditor()
            ->imageEditorAspectRatioOptions(['16:9'])
            ->imageCropAspectRatio('16:9')
            ->imageResizeMode('cover')
            ->imageResizeTargetWidth((string) MarketingCampaign::BANNER_WIDTH)
            ->imageResizeTargetHeight((string) MarketingCampaign::BANNER_HEIGHT)
            ->rule('dimensions:ratio=16/9')
            ->validationMessages(['dimensions' => 'Banner phải có tỉ lệ 16:9 (1280 × 720 px).'])
            ->helperText('JPG, PNG hoặc WebP, tối đa 3 MB. Ảnh khác tỉ lệ sẽ được cắt về 16:9.');
    }

    public static function description(): string
    {
        return 'Kích thước cố định ' . MarketingCampaign::BANNER_WIDTH . ' × ' . MarketingCampaign::BANNER_HEIGHT . ' px (tỉ lệ 16:9).';
    }
}
