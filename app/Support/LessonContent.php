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
    public const BLOCK_TYPES = ['paragraph', 'header', 'list', 'quote', 'delimiter', 'image', 'table', 'code', 'warning', 'quiz'];

    public const QUIZ_MAX_OPTIONS = 8;

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
                // So theo đường dẫn /storage/… (không theo domain): CMS có thể mở bằng domain / cổng khác nhau
                $path = (string) parse_url((string) ($blockData['file']['url'] ?? ''), PHP_URL_PATH);
                $allowedPath = (string) (parse_url($allowedImageUrlPrefix, PHP_URL_PATH) ?: '/');

                if ($path === '' || ! str_starts_with($path, $allowedPath) || str_contains($path, '..')) {
                    continue;
                }
                $blockData['file']['url'] = rtrim($allowedImageUrlPrefix, '/') . '/' . ltrim(substr($path, strlen(rtrim($allowedPath, '/'))), '/');
            }

            $clean[] = array_filter([
                'id' => isset($block['id']) ? substr((string) $block['id'], 0, 32) : null,
                'type' => $type,
                'data' => $type === 'quiz' ? self::cleanQuiz($blockData) : self::cleanValue($blockData, null),
                'tunes' => is_array($block['tunes'] ?? null) ? $block['tunes'] : null,
            ], fn ($value) => $value !== null);
        }

        return [
            'time' => (int) ($data['time'] ?? now()->getTimestampMs()),
            'blocks' => $clean,
            'version' => (string) ($data['version'] ?? ''),
        ];
    }

    /**
     * Khối câu hỏi: chữ thuần, tối đa 8 đáp án, mã đáp án gọn. Không báo lỗi khi chưa hợp lệ
     * (bản nháp vẫn lưu được) — khi xuất bản mới bắt buộc hợp lệ, xem quizProblems().
     *
     * @return array{type: string, question: string, options: list<array{id: string, text: string, correct: bool}>, explanation: string}
     */
    public static function cleanQuiz(array $data): array
    {
        $plain = fn (mixed $v, int $max) => mb_substr(trim(strip_tags((string) $v)), 0, $max);
        $options = [];
        $seen = [];

        foreach (array_slice(is_array($data['options'] ?? null) ? $data['options'] : [], 0, self::QUIZ_MAX_OPTIONS) as $option) {
            $text = $plain($option['text'] ?? '', 500);
            $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($option['id'] ?? '')) ?: substr(md5($text . count($options)), 0, 8);
            if ($text === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $options[] = ['id' => substr($id, 0, 16), 'text' => $text, 'correct' => (bool) ($option['correct'] ?? false)];
        }

        return [
            'type' => ($data['type'] ?? '') === 'multiple' ? 'multiple' : 'single',
            'question' => $plain($data['question'] ?? '', 1000),
            'options' => $options,
            'explanation' => $plain($data['explanation'] ?? '', 2000),
        ];
    }

    /**
     * Lỗi của các câu hỏi trong nội dung (dùng khi xuất bản): thiếu câu hỏi, < 2 đáp án, không có đáp án đúng,
     * "Chọn 1" mà nhiều đáp án đúng.
     *
     * @return list<string>
     */
    public static function quizProblems(?array $content): array
    {
        $problems = [];
        $number = 0;

        foreach ($content['blocks'] ?? [] as $block) {
            if (($block['type'] ?? null) !== 'quiz') {
                continue;
            }
            $number++;
            if ($problem = self::quizProblem(self::cleanQuiz($block['data'] ?? []))) {
                $problems[] = "Câu hỏi {$number}: {$problem}.";
            }
        }

        return $problems;
    }

    private static function quizProblem(array $quiz): ?string
    {
        $correct = count(array_filter($quiz['options'], fn ($o) => $o['correct']));

        return match (true) {
            $quiz['question'] === '' => 'chưa có nội dung câu hỏi',
            count($quiz['options']) < 2 => 'cần ít nhất 2 đáp án',
            $correct === 0 => 'chưa đánh dấu đáp án đúng',
            $quiz['type'] === 'single' && $correct > 1 => '"Chọn 1" chỉ được có 1 đáp án đúng',
            default => null,
        };
    }

    /**
     * Các câu hỏi trong nội dung đã xuất bản: [block_id => quiz] (chỉ câu hợp lệ, có id).
     *
     * @return array<string, array{type: string, question: string, options: list<array{id: string, text: string, correct: bool}>, explanation: string}>
     */
    public static function quizzes(?array $content): array
    {
        $out = [];
        foreach ($content['blocks'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'quiz' && filled($block['id'] ?? null)) {
                $quiz = self::cleanQuiz($block['data'] ?? []);
                if (self::quizProblem($quiz) === null) {
                    $out[(string) $block['id']] = $quiz;
                }
            }
        }

        return $out;
    }

    /**
     * Nội dung đã xuất bản gửi cho app: câu hỏi KHÔNG kèm đáp án đúng và giải thích (server chấm,
     * giải thích trả về sau khi trả lời); link ảnh theo domain của request; bỏ câu hỏi chưa hợp lệ.
     *
     * @param  list<string>  $answeredBlockIds  câu hỏi học viên đã trả lời đúng → answered = true
     */
    public static function forApp(?array $content, array $answeredBlockIds = []): array
    {
        $valid = self::quizzes($content);
        $blocks = [];

        foreach ($content['blocks'] ?? [] as $block) {
            $type = $block['type'] ?? null;

            if ($type === 'quiz') {
                $id = (string) ($block['id'] ?? '');
                if (! isset($valid[$id])) {
                    continue;
                }
                $quiz = $valid[$id];
                $block['data'] = [
                    'type' => $quiz['type'],
                    'question' => $quiz['question'],
                    'options' => array_map(fn ($o) => ['id' => $o['id'], 'text' => $o['text']], $quiz['options']),
                    'answered' => in_array($id, $answeredBlockIds, true),
                ];
            } elseif ($type === 'image' && is_string($block['data']['file']['url'] ?? null)) {
                $path = (string) parse_url($block['data']['file']['url'], PHP_URL_PATH);
                if (str_starts_with($path, '/storage/')) {
                    $block['data']['file']['url'] = \Illuminate\Support\Facades\Storage::disk('public')->url(substr($path, strlen('/storage/')));
                }
            }

            $blocks[] = $block;
        }

        return ['blocks' => $blocks, 'version' => $content['version'] ?? null];
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
