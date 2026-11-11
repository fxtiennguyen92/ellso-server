<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Đổi HTML của RichEditor (mô tả khoá học…) thành các khối nội dung app đã biết hiển thị
 * (giống khối chiến dịch: heading, paragraph, bullets, steps, image, callout). Chữ đậm → "**đậm**".
 * Thẻ lạ: lấy chữ bên trong làm đoạn văn. Không chạy script / style.
 */
class HtmlBlocks
{
    /** @return list<array<string, mixed>> */
    public static function toBlocks(?string $html): array
    {
        $html = trim((string) $html);
        if ($html === '') {
            return [];
        }

        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $root = $doc->getElementById('root') ?? $doc->documentElement;

        $blocks = [];
        foreach ($root->childNodes as $node) {
            self::walk($node, $blocks);
        }

        return array_values(array_filter($blocks, fn ($b) => ($b['type'] === 'image') || trim($b['text'] ?? implode('', $b['items'] ?? [])) !== ''));
    }

    private static function walk(DOMNode $node, array &$blocks): void
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            $text = self::clean($node->textContent);
            if ($text !== '') {
                $blocks[] = ['type' => 'paragraph', 'text' => $text];
            }

            return;
        }
        if (! $node instanceof DOMElement) {
            return;
        }

        $tag = strtolower($node->tagName);
        switch ($tag) {
            case 'h1': case 'h2': case 'h3': case 'h4': case 'h5': case 'h6':
                $blocks[] = ['type' => 'heading', 'text' => self::clean($node->textContent)];
                break;
            case 'p':
                foreach (self::images($node) as $img) {
                    $blocks[] = $img;
                }
                $blocks[] = ['type' => 'paragraph', 'text' => self::inline($node)];
                break;
            case 'ul': case 'ol':
                $items = [];
                foreach ($node->childNodes as $li) {
                    if ($li instanceof DOMElement && strtolower($li->tagName) === 'li') {
                        $t = self::inline($li);
                        if ($t !== '') {
                            $items[] = $t;
                        }
                    }
                }
                if ($items) {
                    $blocks[] = ['type' => $tag === 'ol' ? 'steps' : 'bullets', 'title' => null, 'items' => $items];
                }
                break;
            case 'blockquote':
                $blocks[] = ['type' => 'callout', 'tone' => 'info', 'title' => null, 'text' => self::inline($node)];
                break;
            case 'img': case 'figure':
                foreach ($tag === 'img' ? [self::image($node)] : self::images($node) as $img) {
                    if ($img) {
                        $blocks[] = $img;
                    }
                }
                break;
            case 'script': case 'style': case 'hr': case 'br':
                break;
            default:
                // div / section / thẻ lạ: đi tiếp vào con
                foreach ($node->childNodes as $child) {
                    self::walk($child, $blocks);
                }
        }
    }

    /** Nội dung trong một khối: giữ đậm (**…**) và xuống dòng. */
    private static function inline(DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $out .= $child->textContent;
            } elseif ($child instanceof DOMElement) {
                $t = strtolower($child->tagName);
                if ($t === 'br') {
                    $out .= "\n";
                } elseif (in_array($t, ['strong', 'b'], true)) {
                    $inner = trim(self::inline($child));
                    $out .= $inner !== '' ? '**' . $inner . '**' : '';
                } elseif ($t === 'img') {
                    continue;
                } else {
                    $out .= self::inline($child);
                }
            }
        }

        return trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $out));
    }

    /** @return list<array<string, mixed>> */
    private static function images(DOMElement $node): array
    {
        $out = [];
        foreach ($node->getElementsByTagName('img') as $img) {
            if ($b = self::image($img)) {
                $out[] = $b;
            }
        }

        return $out;
    }

    private static function image(DOMElement $img): ?array
    {
        $src = trim($img->getAttribute('src'));
        if ($src === '' || str_starts_with($src, 'data:')) {
            return null;
        }
        if (! preg_match('#^https?://#', $src)) {
            $src = url($src);
        }

        return ['type' => 'image', 'url' => $src, 'caption' => $img->getAttribute('alt') ?: null];
    }

    private static function clean(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', $s));
    }
}
