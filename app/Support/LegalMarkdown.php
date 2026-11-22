<?php

namespace App\Support;

/**
 * Đổi Markdown đơn giản của trang pháp lý thành khối kiểu Editor.js để app vẽ bằng bộ khối bài học có sẵn:
 * `## ` → header 2, `### ` → header 3, `- ` / `* ` → list unordered, `1. ` → list ordered, dòng khác → paragraph
 * (các dòng liền nhau gộp một đoạn, dòng trống tách đoạn). Trong dòng: **đậm**, *nghiêng*, [chữ](https://…).
 * Mọi HTML người viết gõ vào đều bị escape.
 */
class LegalMarkdown
{
    /** @return list<array{type: string, data: array<string, mixed>}> */
    public static function toBlocks(string $markdown): array
    {
        $blocks = [];
        $para = [];
        $list = null; // ['style' => ..., 'items' => [...]]

        $flushPara = function () use (&$para, &$blocks) {
            if ($para) {
                $blocks[] = ['type' => 'paragraph', 'data' => ['text' => self::inline(implode(' ', $para))]];
                $para = [];
            }
        };
        $flushList = function () use (&$list, &$blocks) {
            if ($list) {
                $blocks[] = ['type' => 'list', 'data' => $list];
                $list = null;
            }
        };

        foreach (preg_split('/\R/u', $markdown) as $raw) {
            $line = trim($raw);
            if ($line === '') {
                $flushPara();
                $flushList();

                continue;
            }
            if (preg_match('/^(#{1,4})\s+(.+)$/u', $line, $m)) {
                $flushPara();
                $flushList();
                $blocks[] = ['type' => 'header', 'data' => ['text' => self::inline($m[2]), 'level' => max(2, strlen($m[1]))]];

                continue;
            }
            if (preg_match('/^(?:[-*]|(\d+)[.)])\s+(.+)$/u', $line, $m)) {
                $flushPara();
                $style = $m[1] !== '' ? 'ordered' : 'unordered';
                if (! $list || $list['style'] !== $style) {
                    $flushList();
                    $list = ['style' => $style, 'items' => []];
                }
                $list['items'][] = self::inline($m[2]);

                continue;
            }
            $flushList();
            $para[] = $line;
        }
        $flushPara();
        $flushList();

        return $blocks;
    }

    private static function inline(string $text): string
    {
        $t = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace('/\*\*(.+?)\*\*/u', '<b>$1</b>', $t);
        $t = preg_replace('/(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?![*\w])/u', '<i>$1</i>', $t);

        return preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^\s)]+|mailto:[^\s)]+)\)/u', fn ($m) => '<a href="'.$m[2].'">'.$m[1].'</a>', $t);
    }
}
