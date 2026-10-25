<?php

namespace App\Support;

use App\Models\PromoCode;
use Illuminate\Support\Facades\Storage;

/**
 * Nội dung trang chi tiết chiến dịch = danh sách khối, lưu ở marketing_campaigns.content
 * theo dạng của Filament Builder: [{ type, data: {...} }, …].
 *
 * resolve() chuẩn hoá cho app (API) và cho bản xem trước trên CMS — bỏ khối trống, đổi ảnh thành URL,
 * gắn thông tin mã khuyến mãi. Chữ trong khối chỉ là văn bản thuần, hỗ trợ **in đậm**.
 *
 * Các khối (app vẽ native từng loại):
 *  paragraph  { text }
 *  heading    { text }
 *  bullets    { title?, items: string[] }          VD: Điều kiện áp dụng
 *  steps      { title?, items: string[] }          VD: Cách áp dụng (đánh số)
 *  image      { url, caption? }
 *  promo_code { code, description?, discount, ends_at?, note? }   ô mã + nút sao chép
 *  callout    { tone: info|warning, title?, text } khung lưu ý
 */
class CampaignContent
{
    public const TYPES = ['paragraph', 'heading', 'bullets', 'steps', 'image', 'promo_code', 'callout'];

    /** @return list<array{type: string} & array<string, mixed>> */
    public static function resolve(?array $blocks, ?callable $imageUrl = null): array
    {
        $imageUrl ??= fn (mixed $path) => is_string($path) ? Storage::disk('public')->url($path) : null;
        $out = [];

        foreach ($blocks ?? [] as $block) {
            $type = $block['type'] ?? null;
            $data = $block['data'] ?? [];

            $resolved = match ($type) {
                'paragraph', 'heading' => self::text($data['text'] ?? null)
                    ? ['type' => $type, 'text' => self::text($data['text'])]
                    : null,
                'bullets', 'steps' => ($items = self::items($data['items'] ?? []))
                    ? ['type' => $type, 'title' => self::text($data['title'] ?? null), 'items' => $items]
                    : null,
                'image' => ($url = ($path = self::path($data['image'] ?? null)) ? $imageUrl($path) : null)
                    ? ['type' => 'image', 'url' => $url, 'caption' => self::text($data['caption'] ?? null)]
                    : null,
                'promo_code' => self::promo($data),
                'callout' => self::text($data['text'] ?? null)
                    ? [
                        'type' => 'callout',
                        'tone' => ($data['tone'] ?? 'info') === 'warning' ? 'warning' : 'info',
                        'title' => self::text($data['title'] ?? null),
                        'text' => self::text($data['text']),
                    ]
                    : null,
                default => null,
            };

            if ($resolved) {
                $out[] = $resolved;
            }
        }

        return $out;
    }

    /** Mẫu nội dung khi tạo chiến dịch mới. */
    public static function starter(): array
    {
        return [
            ['type' => 'paragraph', 'data' => ['text' => '']],
            ['type' => 'steps', 'data' => ['title' => 'Cách áp dụng', 'items' => []]],
            ['type' => 'bullets', 'data' => ['title' => 'Điều kiện áp dụng', 'items' => []]],
        ];
    }

    private static function promo(array $data): ?array
    {
        $promo = filled($data['promo_code_id'] ?? null) ? PromoCode::find($data['promo_code_id']) : null;

        if (! $promo) {
            return null;
        }

        return [
            'type' => 'promo_code',
            'code' => $promo->code,
            'description' => $promo->description,
            'discount' => $promo->discountSummary(),
            'ends_at' => $promo->ends_at?->toIso8601String(),
            'note' => self::text($data['note'] ?? null),
        ];
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** Repeater đơn giản lưu dạng ['a', 'b'] hoặc [['item' => 'a'], …]. */
    private static function items(mixed $items): array
    {
        return array_values(array_filter(array_map(
            fn ($item) => self::text(is_array($item) ? (reset($item) ?: '') : $item),
            is_array($items) ? $items : [],
        )));
    }

    /**
     * FileUpload lưu đường dẫn dạng chuỗi hoặc mảng [uuid => path].
     * Bản xem trước trên CMS có thể nhận file vừa tải lên chưa lưu (TemporaryUploadedFile) — trả nguyên để callback đổi thành URL.
     */
    private static function path(mixed $value): mixed
    {
        if (is_array($value)) {
            $value = reset($value) ?: null;
        }

        return (is_string($value) && $value !== '') || is_object($value) ? $value : null;
    }
}
