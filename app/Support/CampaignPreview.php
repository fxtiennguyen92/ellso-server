<?php

namespace App\Support;

use App\Models\MarketingCampaign;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Bản xem trước chiến dịch trên CMS: 2 màn điện thoại (thẻ banner trên Home + trang chi tiết),
 * dựng từ dữ liệu form đang nhập (kể cả ảnh vừa tải lên chưa lưu). Giao diện này cũng là mẫu
 * để app Expo làm theo (màu, chữ theo ellso-design-system).
 */
class CampaignPreview
{
    public static function html(array $state, ?MarketingCampaign $record = null): string
    {
        $tz = 'Asia/Ho_Chi_Minh';
        $endsAt = filled($state['ends_at'] ?? null) ? Carbon::parse($state['ends_at'], $tz) : null;

        $others = MarketingCampaign::query()->visible()
            ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
            ->count();

        $cta = ($state['cta_type'] ?? MarketingCampaign::CTA_NONE) !== MarketingCampaign::CTA_NONE && filled($state['cta_label'] ?? null)
            ? $state['cta_label']
            : null;

        return view('filament.marketing-campaigns.preview-frame', [
            'title' => trim((string) ($state['title'] ?? '')) ?: 'Tiêu đề chiến dịch',
            'summary' => trim((string) ($state['summary'] ?? '')),
            'banner' => self::imageUrl($state['banner_path'] ?? null),
            'endsLabel' => $endsAt ? 'Đến hết ' . $endsAt->format('H:i d/m/Y') : null,
            'blocks' => CampaignContent::resolve($state['content'] ?? [], fn ($path) => self::imageUrl($path)),
            'cta' => $cta,
            'dots' => min(5, $others + 1),
        ])->render();
    }

    /** Văn bản thuần → HTML an toàn, chỉ cho **in đậm** và xuống dòng. */
    public static function rich(?string $text): HtmlString
    {
        $html = e((string) $text);
        $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html);

        return new HtmlString(nl2br($html, false));
    }

    private static function imageUrl(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = reset($value) ?: null;
        }

        if ($value instanceof TemporaryUploadedFile) {
            try {
                return $value->temporaryUrl();
            } catch (\Throwable) {
                return null;
            }
        }

        return is_string($value) && $value !== '' ? MediaStorage::disk()->url($value) : null;
    }
}
