<?php

namespace App\Http\Controllers\Api;

use App\Enums\VideoStatus;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\User;
use App\Services\DocumentAccess;
use App\Support\DocumentStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;

/**
 * Phát video tài liệu (HLS). Mọi link đều có chữ ký + hạn dùng (không cần header đăng nhập,
 * để trình phát video của app tải được playlist / khoá / đoạn video):
 *
 *  1. App gọi POST /v1/documents/{id}/play (đăng nhập, kiểm tra quyền) → link master.m3u8 hết hạn sau 5 phút.
 *     Quá 5 phút chưa phát / cần phát lại → gọi /play lấy link mới.
 *  2. master.m3u8 → link playlist 480p / 720p (hạn = thời lượng video + 30 phút, để xem hết video).
 *  3. Playlist từng chất lượng: link khoá giải mã + link từng đoạn .ts (S3 presigned / local có chữ ký).
 *  4. Khoá AES-128: kiểm tra lại tài khoản còn hoạt động + còn quyền xem (VD: gói thành viên hết hạn → không phát được).
 * Đoạn video đã mã hoá, lộ link đoạn cũng không xem được nếu không có khoá.
 */
class HlsController extends Controller
{
    public function __construct(private readonly DocumentAccess $access) {}

    /** Link master cho app (gọi từ DocumentController::play). */
    public static function masterUrl(Document $doc, User $user): string
    {
        return URL::temporarySignedRoute('hls.master', now()->addMinutes((int) config('ellso.documents.link_ttl_minutes')), ['document' => $doc->id, 'u' => $user->id]);
    }

    public function master(Request $request, int $document): Response
    {
        $doc = $this->ready($document);
        $lines = ['#EXTM3U', '#EXT-X-VERSION:3'];

        foreach ($doc->hls_variants ?? [] as $v) {
            $lines[] = "#EXT-X-STREAM-INF:BANDWIDTH={$v['bandwidth']},RESOLUTION={$v['width']}x{$v['height']}";
            $lines[] = URL::temporarySignedRoute('hls.variant', $this->innerExpiry($doc), [
                'document' => $doc->id, 'height' => $v['height'], 'u' => $request->query('u'),
            ]);
        }

        return $this->playlist($lines);
    }

    public function variant(Request $request, int $document, int $height): Response
    {
        $doc = $this->ready($document);
        abort_unless(collect($doc->hls_variants)->contains('height', $height), 404);

        $dir = "{$doc->hls_path}/{$height}";
        $source = DocumentStorage::disk()->get("{$dir}/index.m3u8");
        abort_if($source === null, 404);

        $minutes = (int) ceil(now()->diffInMinutes($this->innerExpiry($doc)));
        $keyUrl = URL::temporarySignedRoute('hls.key', $this->innerExpiry($doc), ['document' => $doc->id, 'u' => $request->query('u')]);

        $lines = [];
        foreach (preg_split('/\r?\n/', trim($source)) as $line) {
            if (str_starts_with($line, '#EXT-X-KEY')) {
                $line = preg_replace('/URI="[^"]*"/', 'URI="' . $keyUrl . '"', $line);
            } elseif ($line !== '' && ! str_starts_with($line, '#')) {
                $line = DocumentStorage::url("{$dir}/" . basename($line), $minutes);
            }
            $lines[] = $line;
        }

        return $this->playlist($lines);
    }

    public function key(Request $request, int $document): Response
    {
        $doc = $this->ready($document);
        $user = User::query()->whereKey($request->query('u'))->where('is_active', true)->first();
        abort_unless($user && $this->access->canView($user, $doc), 403);

        return response(base64_decode((string) $doc->hls_key), 200, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function ready(int $id): Document
    {
        $doc = Document::query()->find($id);
        abort_unless($doc && $doc->video_status === VideoStatus::Ready && $doc->hls_path, 404);

        return $doc;
    }

    /** Link bên trong playlist sống đủ để xem hết video: thời lượng + 30 phút (tối thiểu 1 giờ). */
    private function innerExpiry(Document $doc): \Illuminate\Support\Carbon
    {
        return now()->addMinutes(max(60, (int) ceil(($doc->duration_seconds ?? 0) / 60) + 30));
    }

    private function playlist(array $lines): Response
    {
        return response(implode("\n", $lines) . "\n", 200, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
