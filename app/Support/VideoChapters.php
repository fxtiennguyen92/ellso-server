<?php

namespace App\Support;

/**
 * Mốc thời gian video: admin nhập dạng văn bản, mỗi dòng một mốc "mm:ss Nội dung" (hoặc "h:mm:ss Nội dung").
 * Cho phép ngăn cách bằng khoảng trắng, "-", "–", "—" hoặc ":" sau mốc. Dòng trống bị bỏ qua.
 * Lưu dạng [{start: giây, title}] sắp tăng dần; app bấm vào mốc thì tua tới start.
 */
class VideoChapters
{
    public const MAX = 100;

    private const LINE = '/^\s*(?:(\d{1,2}):)?(\d{1,3}):(\d{2})\s*(?:[-–—:|]\s*)?(.+?)\s*$/u';

    /**
     * @return array{chapters: list<array{start:int, title:string}>, errors: list<string>}
     */
    public static function parse(?string $text, ?int $durationSeconds = null): array
    {
        $chapters = [];
        $errors = [];
        $lines = preg_split('/\R/u', (string) $text) ?: [];

        foreach ($lines as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            $n = $i + 1;
            if (! preg_match(self::LINE, $line, $m)) {
                $errors[] = "Dòng {$n}: cần dạng \"mm:ss Nội dung\" (VD: 02:15 Cài đặt).";

                continue;
            }

            [$hours, $minutes, $seconds, $title] = [(int) $m[1], (int) $m[2], (int) $m[3], trim($m[4])];
            if ($seconds > 59 || ($hours > 0 && $minutes > 59)) {
                $errors[] = "Dòng {$n}: thời gian không hợp lệ ({$m[0]}).";

                continue;
            }
            if (mb_strlen($title) > 200) {
                $errors[] = "Dòng {$n}: nội dung tối đa 200 ký tự.";

                continue;
            }

            $start = $hours * 3600 + $minutes * 60 + $seconds;
            if ($durationSeconds && $start >= $durationSeconds) {
                $errors[] = "Dòng {$n}: mốc " . self::time($start) . ' vượt quá độ dài video (' . self::time($durationSeconds) . ').';

                continue;
            }
            $chapters[] = ['start' => $start, 'title' => $title];
        }

        usort($chapters, fn ($a, $b) => $a['start'] <=> $b['start']);
        $starts = array_column($chapters, 'start');
        foreach (array_unique(array_diff_assoc($starts, array_unique($starts))) as $dup) {
            $errors[] = 'Mốc ' . self::time($dup) . ' bị trùng.';
        }
        if (count($chapters) > self::MAX) {
            $errors[] = 'Tối đa ' . self::MAX . ' mốc.';
        }

        return ['chapters' => $chapters, 'errors' => $errors];
    }

    /** Ngược lại: danh sách mốc → văn bản để sửa trên CMS. */
    public static function format(?array $chapters): string
    {
        return collect($chapters ?? [])->map(fn ($c) => self::time((int) $c['start']) . ' ' . $c['title'])->join("\n");
    }

    /** Giây → "mm:ss" (hoặc "h:mm:ss" khi ≥ 1 giờ). */
    public static function time(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        return $h ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%02d:%02d', $m, $s);
    }

    /** Dạng trả cho app. @return list<array{start:int, time:string, title:string}> */
    public static function present(?array $chapters): array
    {
        return array_map(fn ($c) => ['start' => (int) $c['start'], 'time' => self::time((int) $c['start']), 'title' => $c['title']], $chapters ?? []);
    }
}
