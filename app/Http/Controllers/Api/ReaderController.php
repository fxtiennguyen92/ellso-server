<?php

namespace App\Http\Controllers\Api;

use App\Enums\DocumentType;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Support\DocumentStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Trình đọc sách cho app (PDF bằng pdf.js, EPUB bằng epub.js — thư viện nằm ở public/vendor/reader, không dùng CDN).
 * App mở `reader_url` (trả về từ POST /documents/{id}/preview hoặc /open) trong WebView (iOS / Android) hoặc iframe (web).
 * File sách đi qua link có chữ ký trên CHÍNH domain API (stream từ ổ tài liệu, kể cả S3) → không cần CORS.
 */
class ReaderController extends Controller
{
    public const KIND_PREVIEW = 'preview';

    public const KIND_FULL = 'full';

    /** Link trang đọc (hạn file = $minutes). */
    public static function readerUrl(Document $doc, string $kind, int $minutes): string
    {
        $file = URL::temporarySignedRoute('reader.file', now()->addMinutes($minutes), ['id' => $doc->id, 'kind' => $kind]);

        return route('reader.page', [
            'file' => $file,
            'format' => $doc->file_format === 'epub' ? 'epub' : 'pdf',
            'mode' => $kind,
        ]);
    }

    /** GET /reader?file=&format=pdf|epub&mode=preview|full&theme=light|dark */
    public function page(Request $request): View
    {
        $data = $request->validate([
            'file' => ['required', 'url'],
            'format' => ['required', 'in:pdf,epub'],
            'mode' => ['nullable', 'in:preview,full'],
            'theme' => ['nullable', 'in:light,dark'],
        ]);

        return view('reader', [
            'file' => $data['file'],
            'format' => $data['format'],
            'mode' => $data['mode'] ?? self::KIND_PREVIEW,
            'dark' => ($data['theme'] ?? 'light') === 'dark',
        ]);
    }

    /** GET /reader/file/{id}/{kind} (có chữ ký, hạn ngắn) — stream file đọc thử / cả cuốn. */
    public function file(int $id, string $kind): StreamedResponse
    {
        $doc = Document::query()->whereKey($id)->where('type', DocumentType::Book)->firstOrFail();
        $path = $kind === self::KIND_FULL ? $doc->file_path : $doc->preview_path;
        abort_unless($path && DocumentStorage::disk()->exists($path), 404);

        $type = $doc->file_format === 'epub' ? 'application/epub+zip' : 'application/pdf';

        return DocumentStorage::disk()->response($path, null, [
            'Content-Type' => $type,
            'Cache-Control' => 'private, max-age=300',
            'Content-Disposition' => 'inline',
        ]);
    }
}
