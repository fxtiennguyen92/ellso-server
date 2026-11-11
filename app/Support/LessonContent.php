<?php

namespace App\Support;

use App\Enums\VideoStatus;
use App\Models\LessonVideo;
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
    public const BLOCK_TYPES = ['paragraph', 'header', 'list', 'quote', 'delimiter', 'image', 'table', 'code', 'warning', 'quiz', 'video'];

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
    /**
     * @param  list<int>|null  $allowedVideoIds  id video của chính bài (LessonVideo); null = không kiểm tra
     */
    public static function sanitize(array $data, string $allowedImageUrlPrefix, ?array $allowedVideoIds = null): array
    {
        $blocks = $data['blocks'] ?? null;

        if (! is_array($blocks)) {
            throw ValidationException::withMessages(['content' => 'Dữ liệu nội dung không hợp lệ.']);
        }

        if (count($blocks) > self::MAX_BLOCKS) {
            throw ValidationException::withMessages(['content' => 'Bài học quá dài (tối đa '.self::MAX_BLOCKS.' khối).']);
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
                $blockData['file']['url'] = rtrim($allowedImageUrlPrefix, '/').'/'.ltrim(substr($path, strlen(rtrim($allowedPath, '/'))), '/');
            }

            $clean[] = array_filter([
                'id' => isset($block['id']) ? substr((string) $block['id'], 0, 32) : null,
                'type' => $type,
                'data' => match ($type) {
                    'quiz' => self::cleanQuiz($blockData),
                    'video' => self::cleanVideo($blockData, $allowedVideoIds),
                    default => self::cleanValue($blockData, null),
                },
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
            $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($option['id'] ?? '')) ?: substr(md5($text.count($options)), 0, 8);
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
     * Khối video: id video của bài, chú thích, mốc thời gian (văn bản mỗi dòng "mm:ss Nội dung" — giữ nguyên để sửa tiếp,
     * kiểm tra khi xuất bản, chuyển thành [{start, time, title}] khi gửi app).
     *
     * @return array{video_id: int|null, caption: string, chapters: string}
     */
    public static function cleanVideo(array $data, ?array $allowedVideoIds = null): array
    {
        $id = isset($data['video_id']) && is_numeric($data['video_id']) ? (int) $data['video_id'] : null;
        if ($id !== null && $allowedVideoIds !== null && ! in_array($id, $allowedVideoIds, true)) {
            $id = null;
        }

        return [
            'video_id' => $id,
            'caption' => mb_substr(trim(strip_tags((string) ($data['caption'] ?? ''))), 0, 300),
            'chapters' => mb_substr(trim(strip_tags((string) ($data['chapters'] ?? ''))), 0, 10000),
        ];
    }

    /**
     * Lỗi của các khối video (dùng khi xuất bản): chưa tải video, video chưa xử lý xong / lỗi, mốc thời gian sai.
     *
     * @param  array<int, LessonVideo>  $videos  video của bài, theo id
     * @return list<string>
     */
    public static function videoProblems(?array $content, array $videos): array
    {
        $problems = [];
        $number = 0;

        foreach ($content['blocks'] ?? [] as $block) {
            if (($block['type'] ?? null) !== 'video') {
                continue;
            }
            $number++;
            $data = self::cleanVideo($block['data'] ?? []);
            $video = $data['video_id'] ? ($videos[$data['video_id']] ?? null) : null;

            if (! $video) {
                $problems[] = "Video {$number}: chưa tải video lên.";

                continue;
            }
            if ($video->video_status === VideoStatus::Failed) {
                $problems[] = "Video {$number}: xử lý lỗi — tải lại video khác.";
            } elseif (! $video->isReady()) {
                $problems[] = "Video {$number}: đang xử lý, chờ xong rồi xuất bản.";
            }
            foreach (VideoChapters::parse($data['chapters'], $video->isReady() ? $video->duration_seconds : null)['errors'] as $error) {
                $problems[] = "Video {$number} – mốc thời gian: {$error}";
            }
        }

        return $problems;
    }

    /** Id video được dùng trong nội dung. @return list<int> */
    public static function videoIds(?array $content): array
    {
        return collect($content['blocks'] ?? [])
            ->where('type', 'video')
            ->map(fn ($b) => isset($b['data']['video_id']) ? (int) $b['data']['video_id'] : null)
            ->filter()
            ->unique()
            ->values()
            ->all();
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
     * @param  list<string>  $answeredBlockIds  câu hỏi học viên đã trả lời đúng → answered = true (+ my_answer, explanation)
     * @param  array<string, list<string>>  $myAnswers  đáp án học viên đã chọn ở lần trả lời đúng gần nhất
     */
    public static function forApp(?array $content, array $answeredBlockIds = [], array $videos = [], array $myAnswers = []): array
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
                    'answered' => $answered = in_array($id, $answeredBlockIds, true),
                    // Đã trả lời đúng: gửi lại đáp án học viên đã chọn + giải thích để xem lại bài thấy đúng như lúc làm
                    'my_answer' => $answered ? array_values($myAnswers[$id] ?? []) : null,
                    'explanation' => $answered ? ($quiz['explanation'] ?? null) : null,
                ];
            } elseif ($type === 'video') {
                // Video bài học: chỉ gửi khi đã xử lý xong; app gọi POST /lessons/{id}/videos/{video}/play để lấy link phát
                $data = self::cleanVideo($block['data'] ?? []);
                $video = $data['video_id'] ? ($videos[$data['video_id']] ?? null) : null;
                if (! $video?->isReady()) {
                    continue;
                }
                $block['data'] = [
                    'video_id' => $video->id,
                    'duration_seconds' => $video->duration_seconds,
                    'caption' => $data['caption'],
                    'chapters' => VideoChapters::present(VideoChapters::parse($data['chapters'], $video->duration_seconds)['chapters']),
                ];
            } elseif ($type === 'image' && is_string($block['data']['file']['url'] ?? null)) {
                // Ảnh của hệ thống → dựng lại link theo ổ ảnh hiện tại (local: domain đang gọi; S3: CloudFront).
                // Nhận cả /storage/… (ảnh soạn trên local) lẫn /media/… (ảnh trên S3).
                $path = (string) parse_url($block['data']['file']['url'], PHP_URL_PATH);
                foreach (array_unique(['/storage/', (string) parse_url(MediaStorage::baseUrl(), PHP_URL_PATH)]) as $prefix) {
                    if ($prefix !== '/' && str_starts_with($path, $prefix)) {
                        $block['data']['file']['url'] = MediaStorage::url(substr($path, strlen($prefix)));
                        break;
                    }
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
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
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
