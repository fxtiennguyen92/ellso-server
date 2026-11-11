<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Cắt video thành HLS (480p + 720p, không phóng to quá độ phân giải gốc), mã hoá AES-128,
 * đẩy lên ổ tài liệu: {dir}/{480|720}/index.m3u8 + seg_XXXX.ts. Dùng chung cho video tài liệu và video bài học.
 * Cần ffmpeg + ffprobe (FFMPEG_BINARY / FFPROBE_BINARY).
 */
class HlsEncoder
{
    public function __construct(private readonly int $timeout = 3600) {}

    /**
     * @param  string  $sourcePath  file gốc trên ổ tài liệu
     * @param  string  $targetDir  thư mục đích trên ổ tài liệu (mới mỗi lần xử lý)
     * @return array{key: string, variants: list<array{height:int,width:int,bandwidth:int}>, duration: int}
     */
    public function encode(string $sourcePath, string $targetDir): array
    {
        $work = storage_path('app/tmp/hls-' . Str::random(10));
        File::ensureDirectoryExists($work . '/out');

        try {
            $disk = DocumentStorage::disk();
            $source = $work . '/source';
            $in = $disk->readStream($sourcePath);
            if (! $in) {
                throw new RuntimeException('Không đọc được file video gốc.');
            }
            $outStream = fopen($source, 'wb');
            stream_copy_to_stream($in, $outStream);
            fclose($outStream);
            is_resource($in) && fclose($in);

            [$duration, $height] = $this->probe($source);

            // Khoá AES-128 + IV ngẫu nhiên; URI "key.key" được thay bằng link có chữ ký khi phát
            $key = random_bytes(16);
            file_put_contents($work . '/enc.key', $key);
            file_put_contents($work . '/keyinfo', "key.key\n{$work}/enc.key\n" . bin2hex(random_bytes(16)) . "\n");

            $variants = [];
            foreach (config('ellso.documents.hls_variants') as $h => [$w, $vb, $ab]) {
                if ($h > 480 && $height && $height < $h) {
                    continue; // không phóng to video thấp hơn 720p (480p luôn có)
                }
                $dir = $work . '/out/' . $h;
                File::ensureDirectoryExists($dir);
                $this->run([
                    config('ellso.documents.ffmpeg'), '-hide_banner', '-loglevel', 'error', '-y', '-i', $source,
                    '-vf', "scale=-2:{$h}",
                    '-c:v', 'libx264', '-preset', 'veryfast', '-profile:v', 'main', '-pix_fmt', 'yuv420p', // yuv420p: điện thoại nào cũng phát được
                    '-b:v', $vb, '-maxrate', $vb, '-bufsize', ((int) $vb * 2) . 'k',
                    '-g', '48', '-keyint_min', '48', '-sc_threshold', '0',
                    '-c:a', 'aac', '-b:a', $ab, '-ac', '2',
                    '-hls_time', (string) config('ellso.documents.hls_segment_seconds'),
                    '-hls_playlist_type', 'vod',
                    '-hls_key_info_file', $work . '/keyinfo',
                    '-hls_segment_filename', $dir . '/seg_%04d.ts',
                    $dir . '/index.m3u8',
                ]);
                $variants[] = ['height' => $h, 'width' => $w, 'bandwidth' => ((int) $vb + (int) $ab) * 1000];
            }

            foreach (File::allFiles($work . '/out') as $file) {
                $stream = fopen($file->getPathname(), 'rb');
                $disk->writeStream($targetDir . '/' . str_replace('\\', '/', $file->getRelativePathname()), $stream);
                is_resource($stream) && fclose($stream);
            }

            return ['key' => base64_encode($key), 'variants' => $variants, 'duration' => (int) round($duration)];
        } finally {
            File::deleteDirectory($work);
        }
    }

    /** @return array{0: float, 1: int|null} thời lượng (giây), chiều cao video */
    private function probe(string $file): array
    {
        $json = $this->run([
            config('ellso.documents.ffprobe'), '-v', 'error', '-select_streams', 'v:0',
            '-show_entries', 'stream=height:format=duration', '-of', 'json', $file,
        ]);
        $data = json_decode($json, true) ?: [];
        if (empty($data['streams'])) {
            throw new RuntimeException('File không phải video hợp lệ.');
        }

        return [(float) ($data['format']['duration'] ?? 0), isset($data['streams'][0]['height']) ? (int) $data['streams'][0]['height'] : null];
    }

    private function run(array $command): string
    {
        $process = new Process($command);
        $process->setTimeout($this->timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            $error = trim($process->getErrorOutput()) ?: 'ffmpeg lỗi';
            throw new RuntimeException(mb_substr($error, -800)); // giữ phần cuối (dòng lỗi thật)
        }

        return $process->getOutput();
    }
}
