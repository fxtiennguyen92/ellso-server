<?php

namespace App\Http\Controllers\Api;

use App\Enums\VideoStatus;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\LessonVideo;
use App\Models\User;
use App\Services\DocumentAccess;
use App\Services\LearningProgress;
use App\Support\DocumentStorage;
use App\Support\LessonContent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * Phát video HLS: video tài liệu (routes hls.*) và video bài học (routes hls.lesson.*). Mọi link đều có chữ ký + hạn dùng (không cần header đăng nhập,
 * để trình phát video của app tải được playlist / khoá / đoạn video):
 *
 *  1. App gọi POST /v1/documents/{id}/play (đăng nhập, kiểm tra quyền) → link master.m3u8 hết hạn sau 5 phút.
 *     Quá 5 phút chưa phát / cần phát lại → gọi /play lấy link mới.
 *  2. master.m3u8 → link playlist 480p / 720p (hạn = thời lượng video + 30 phút, để xem hết video).
 *  3. Playlist từng chất lượng: link khoá giải mã + link từng đoạn .ts (S3 presigned / local có chữ ký).
 *  4. Khoá AES-128: kiểm tra lại tài khoản còn hoạt động + còn quyền xem (VD: gói thành viên hết hạn → không phát được).
 * Đoạn video đã mã hoá, lộ link đoạn cũng không xem được nếu không có khoá.
 * Video bài học: khoá kiểm tra học viên còn mở được bài (ghi danh + bài đã mở, hoặc bài học thử) và video
 * vẫn nằm trong nội dung đã xuất bản của bài.
 */
class HlsController extends Controller
{
    public function __construct(
        private readonly DocumentAccess $access,
        private readonly LearningProgress $progress,
    ) {}

    /** Link master cho app (gọi từ DocumentController::play). */
    public static function masterUrl(Document $doc, User $user): string
    {
        return URL::temporarySignedRoute('hls.master', now()->addMinutes((int) config('ellso.documents.link_ttl_minutes')), ['document' => $doc->id, 'u' => $user->id]);
    }

    /** Link master video bài học (gọi từ LessonController::playVideo). */
    public static function lessonMasterUrl(LessonVideo $video, User $user): string
    {
        return URL::temporarySignedRoute('hls.lesson.master', now()->addMinutes((int) config('ellso.documents.link_ttl_minutes')), ['video' => $video->id, 'u' => $user->id]);
    }

    // ── Video tài liệu ───────────────────────────────────────

    public function master(Request $request, int $document): Response
    {
        return $this->masterFor($this->ready($document), 'hls.variant', ['document' => $document], $request);
    }

    public function variant(Request $request, int $document, int $height): Response
    {
        return $this->variantFor($this->ready($document), $height, 'hls.key', ['document' => $document], $request);
    }

    public function key(Request $request, int $document): Response
    {
        $doc = $this->ready($document);
        $user = User::query()->whereKey($request->query('u'))->where('is_active', true)->first();
        abort_unless($user && $this->access->canView($user, $doc), 403);

        return $this->keyResponse($doc->hls_key);
    }

    // ── Video bài học ────────────────────────────────────────

    public function lessonMaster(Request $request, int $video): Response
    {
        return $this->masterFor($this->readyLessonVideo($video), 'hls.lesson.variant', ['video' => $video], $request);
    }

    public function lessonVariant(Request $request, int $video, int $height): Response
    {
        return $this->variantFor($this->readyLessonVideo($video), $height, 'hls.lesson.key', ['video' => $video], $request);
    }

    public function lessonKey(Request $request, int $video): Response
    {
        $lessonVideo = $this->readyLessonVideo($video);
        $user = User::query()->whereKey($request->query('u'))->where('is_active', true)->first();
        $lesson = $lessonVideo->lesson;
        abort_unless(
            $user && $lesson
            && in_array($lessonVideo->id, LessonContent::videoIds($lesson->content_published), true)
            && $this->progress->access($user, $lesson)['allowed'],
            403,
        );

        return $this->keyResponse($lessonVideo->hls_key);
    }

    // ── Chung ────────────────────────────────────────────────

    /** @param  Document|LessonVideo  $source */
    private function masterFor($source, string $variantRoute, array $params, Request $request): Response
    {
        $lines = ['#EXTM3U', '#EXT-X-VERSION:3'];

        foreach ($source->hls_variants ?? [] as $v) {
            $lines[] = "#EXT-X-STREAM-INF:BANDWIDTH={$v['bandwidth']},RESOLUTION={$v['width']}x{$v['height']}";
            $lines[] = URL::temporarySignedRoute($variantRoute, $this->innerExpiry($source), [
                ...$params, 'height' => $v['height'], 'u' => $request->query('u'),
            ]);
        }

        return $this->playlist($lines);
    }

    /** @param  Document|LessonVideo  $source */
    private function variantFor($source, int $height, string $keyRoute, array $params, Request $request): Response
    {
        abort_unless(collect($source->hls_variants)->contains('height', $height), 404);

        $dir = "{$source->hls_path}/{$height}";
        $content = DocumentStorage::disk()->get("{$dir}/index.m3u8");
        abort_if($content === null, 404);

        $minutes = (int) ceil(now()->diffInMinutes($this->innerExpiry($source)));
        $keyUrl = URL::temporarySignedRoute($keyRoute, $this->innerExpiry($source), [...$params, 'u' => $request->query('u')]);

        $lines = [];
        foreach (preg_split('/\r?\n/', trim($content)) as $line) {
            if (str_starts_with($line, '#EXT-X-KEY')) {
                $line = preg_replace('/URI="[^"]*"/', 'URI="'.$keyUrl.'"', $line);
            } elseif ($line !== '' && ! str_starts_with($line, '#')) {
                $line = DocumentStorage::url("{$dir}/".basename($line), $minutes);
            }
            $lines[] = $line;
        }

        return $this->playlist($lines);
    }

    private function keyResponse(?string $key): Response
    {
        return response(base64_decode((string) $key), 200, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function readyLessonVideo(int $id): LessonVideo
    {
        $video = LessonVideo::query()->with('lesson')->find($id);
        abort_unless($video && $video->isReady(), 404);

        return $video;
    }

    private function ready(int $id): Document
    {
        $doc = Document::query()->find($id);
        abort_unless($doc && $doc->video_status === VideoStatus::Ready && $doc->hls_path, 404);

        return $doc;
    }

    /**
     * Link bên trong playlist sống đủ để xem hết video: thời lượng + 30 phút (tối thiểu 1 giờ).
     *
     * @param  Document|LessonVideo  $source
     */
    private function innerExpiry($source): Carbon
    {
        return now()->addMinutes(max(60, (int) ceil(($source->duration_seconds ?? 0) / 60) + 30));
    }

    private function playlist(array $lines): Response
    {
        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
