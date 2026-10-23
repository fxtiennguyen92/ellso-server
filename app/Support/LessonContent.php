<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Validation\ValidationException;

/**
 * Kiểm tra & làm sạch nội dung Editor.js trước khi lưu.
 *
 * - Chỉ nhận các loại khối đang hỗ trợ (BLOCK_TYPES).
 * - Chữ trong khối chỉ giữ thẻ định dạng an toàn (b, i, a, mark, code, br) — bỏ script, style, on*…
 * - Link chỉ chấp nhận http(s) / mailto.
 * - Ảnh chỉ chấp nhận ảnh nằm trên storage của hệ thống (app cần tải được để học offline).
 *
 * Khi thêm plugin mới (PDF, podcast, video, quiz…) → thêm loại khối vào BLOCK_TYPES.
 */
class LessonContent
{
    public const BLOCK_TYPES = ['paragraph', 'header', 'list', 'quote', 'delimiter', 'image', 'table', 'code', 'warning'];

    public const MAX_BLOCKS = 500;

    private const INLINE_TAGS = ['b', 'strong', 'i', 'em', 'a', 'mark', 'code', 'br', 'u'];

    /** Giữ nguyên dạng chữ thuần (không phải HTML) cho các trường này. */
    private const PLAIN_KEYS = ['code', 'style', 'alignment', 'url', 'id', 'type'];

    /**
     * @param  array<string, mixed>  $data  output của editor.save()
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function sanitize(array $data, string $allowedImageUrlPrefix): array
    {
        $blocks = $data['blocks'] ?? null;

        if (! is_array($blocks)) {
            throw ValidationException::withMessages(['content' => 'Dữ liệu nội dung không hợp lệ.']);
        }

        if (count($blocks) > self::MAX_BLOCKS) {
            throw ValidationException::withMessages(['content' => 'Bài học quá dài (tối đa ' . self::MAX_BLOCKS . ' khối).']);
        }

        $clean = [];

        foreach ($blocks as $block) {
            $type = $block['type'] ?? null;

            if (! is_array($block) || ! in_array($type, self::BLOCK_TYPES, true)) {
                continue; // bỏ khối lạ thay vì báo lỗi, để bài vẫn lưu được
            }

            $blockData = is_array($block['data'] ?? null) ? $block['data'] : [];

            if ($type === 'image') {
                $url = (string) ($blockData['file']['url'] ?? '');

                if (! str_starts_with($url, $allowedImageUrlPrefix)) {
                    continue;
                }
            }

            $clean[] = array_filter([
                'id' => isset($block['id']) ? substr((string) $block['id'], 0, 32) : null,
                'type' => $type,
                'data' => self::cleanValue($blockData, null),
                'tunes' => is_array($block['tunes'] ?? null) ? $block['tunes'] : null,
            ], fn ($value) => $value !== null);
        }

        return [
            'time' => (int) ($data['time'] ?? now()->getTimestampMs()),
            'blocks' => $clean,
            'version' => (string) ($data['version'] ?? ''),
        ];
    }

    private static function cleanValue(mixed $value, ?string $key): mixed
    {
        if (is_array($value)) {
            $result = [];

            foreach ($value as $childKey => $child) {
                $result[$childKey] = self::cleanValue($child, is_string($childKey) ? $childKey : $key);
            }

            return $result;
        }

        if (! is_string($value) || in_array($key, self::PLAIN_KEYS, true)) {
            return $value;
        }

        // Chuỗi không có thẻ HTML thì giữ nguyên
        return str_contains($value, '<') ? self::cleanInlineHtml($value) : $value;
    }

    /** Chỉ giữ thẻ định dạng trong dòng; link chỉ giữ href an toàn. */
    public static function cleanInlineHtml(string $html): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('root') ?? $doc->documentElement;
        self::cleanNode($root);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out;
    }

    private static function cleanNode(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                if ($child->nodeType === XML_COMMENT_NODE) {
                    $node->removeChild($child);
                }

                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed'], true)) {
                $node->removeChild($child);

                continue;
            }

            self::cleanNode($child);

            if (! in_array($tag, self::INLINE_TAGS, true)) {
                // Thẻ không cho phép: bỏ thẻ, giữ chữ bên trong
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);

                continue;
            }

            foreach (iterator_to_array($child->attributes) as $attribute) {
                $name = strtolower($attribute->name);
                $keep = $tag === 'a' && $name === 'href' && preg_match('#^(https?://|mailto:)#i', trim($attribute->value));
                $keep = $keep || ($tag === 'mark' && $name === 'class') || ($tag === 'code' && $name === 'class');

                if (! $keep) {
                    $child->removeAttribute($attribute->name);
                }
            }

            if ($tag === 'a' && $child->hasAttribute('href')) {
                $child->setAttribute('target', '_blank');
                $child->setAttribute('rel', 'noopener');
            }
        }
    }
}