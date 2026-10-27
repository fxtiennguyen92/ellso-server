<?php

namespace App\Http\Controllers\Api;

use App\Enums\DocumentType;
use App\Enums\VideoStatus;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentTopic;
use App\Models\User;
use App\Services\DocumentAccess;
use App\Support\DocumentStorage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tài liệu cho app (đăng nhập). Server tính sẵn `unlocked` cho học viên; app chỉ hiển thị.
 * - GET    /documents?type=video|pdf|book&topic_id=&teacher_id=&q=   danh sách (mới nhất trước)
 * - GET    /documents/topics                           chủ đề để lọc
 * - GET    /documents/{id}                             chi tiết + điều kiện mở
 * - POST   /documents/{id}/play                        video: link HLS (5 phút)
 * - POST   /documents/{id}/open                        PDF / sách: link xem (5 phút) + link tải (sách cho tải)
 * - POST   /documents/{id}/save · DELETE …/save        lưu / bỏ lưu
 * - GET    /my/documents                               đã lưu + đã mua
 */
class DocumentController extends Controller
{
    public function __construct(private readonly DocumentAccess $access) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['nullable', 'in:video,pdf,book'],
            'topic_id' => ['nullable', 'integer'],
            'teacher_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $docs = Document::query()
            ->published()
            ->when($data['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($data['topic_id'] ?? null, fn ($q, $topic) => $q->where('document_topic_id', $topic))
            ->when($data['teacher_id'] ?? null, fn ($q, $teacher) => $q->where('author_id', $teacher))
            ->when($data['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w->where('title', 'like', "%{$term}%")->orWhere(fn ($a) => $a->whereAuthorLike($term))))
            ->latest('published_at')
            ->with(['authorUser', 'links.course', 'links.lesson'])
            ->limit(200)
            ->get();

        return response()->json(['data' => $this->present($docs, $request->user())]);
    }

    public function topics(): JsonResponse
    {
        return response()->json(['data' => DocumentTopic::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $doc = $this->find($id);

        return response()->json(['data' => [
            ...$this->present(new Collection([$doc]), $request->user())[0],
            'description' => $doc->description,
            'file' => $doc->type === DocumentType::Video ? null : [
                'format' => $doc->file_format,
                'size' => $doc->file_size,
                'downloadable' => $doc->allow_download,
            ],
        ]]);
    }

    public function play(Request $request, int $id): JsonResponse
    {
        $doc = $this->find($id);
        abort_unless($doc->type === DocumentType::Video && $doc->video_status === VideoStatus::Ready, 422, 'Tài liệu này không phải video.');
        $this->authorizeView($request->user(), $doc);

        $ttl = (int) config('ellso.documents.link_ttl_minutes');

        return response()->json(['data' => [
            'url' => HlsController::masterUrl($doc, $request->user()),
            'expires_at' => now()->addMinutes($ttl)->toIso8601String(),
            'duration_seconds' => $doc->duration_seconds,
            'qualities' => collect($doc->hls_variants)->pluck('height')->values(),
        ]]);
    }

    public function open(Request $request, int $id): JsonResponse
    {
        $doc = $this->find($id);
        abort_if($doc->type === DocumentType::Video || ! $doc->file_path, 422, 'Tài liệu này không có file để mở.');
        $this->authorizeView($request->user(), $doc);

        $ttl = (int) config('ellso.documents.link_ttl_minutes');

        return response()->json(['data' => [
            'url' => DocumentStorage::url($doc->file_path, $ttl),
            'format' => $doc->file_format,
            'file_name' => $doc->file_name,
            'download_url' => $doc->allow_download ? DocumentStorage::downloadUrl($doc->file_path, $doc->file_name ?: basename($doc->file_path), $ttl) : null,
            'expires_at' => now()->addMinutes($ttl)->toIso8601String(),
        ]]);
    }

    public function save(Request $request, int $id): JsonResponse
    {
        $doc = $this->find($id);
        $request->user()->savedDocuments()->syncWithoutDetaching([$doc->id => ['created_at' => now()]]);

        return response()->json(['data' => ['saved' => true]]);
    }

    public function unsave(Request $request, int $id): JsonResponse
    {
        $request->user()->savedDocuments()->detach($id);

        return response()->json(['data' => ['saved' => false]]);
    }

    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();
        $saved = $user->savedDocuments()->published()->with(['authorUser', 'links.course', 'links.lesson'])->orderByPivot('created_at', 'desc')->get();
        $bought = Document::query()->published()
            ->whereHas('purchases', fn ($q) => $q->where('user_id', $user->id))
            ->with(['authorUser', 'links.course', 'links.lesson'])
            ->get();

        return response()->json(['data' => [
            'saved' => $this->present($saved, $user),
            'bought' => $this->present($bought, $user),
        ]]);
    }

    private function find(int $id): Document
    {
        $doc = Document::query()->published()->with(['authorUser', 'links.course', 'links.lesson', 'topic'])->find($id);
        abort_unless($doc, 404, 'Tài liệu không tồn tại.');

        return $doc;
    }

    private function authorizeView(User $user, Document $doc): void
    {
        if (! $this->access->canView($user, $doc)) {
            abort(response()->json(['message' => 'Bạn chưa mở được tài liệu này.', 'code' => 'locked', 'access' => $this->access->conditions($doc)], 403));
        }
    }

    /** Định dạng giống src/data/documents.ts › Doc của app. */
    private function present(Collection $docs, User $user): array
    {
        $ids = $docs->pluck('id');
        $saved = $user->savedDocuments()->whereIn('documents.id', $ids)->pluck('documents.id')->all();
        $purchases = $user->documentPurchases()->whereIn('document_id', $ids)->get()->keyBy('document_id');

        return $docs->map(fn (Document $d) => [
            'id' => $d->id,
            'type' => $d->type->value,
            'title' => $d->title,
            'author' => $d->authorName(),
            // Tác giả là giảng viên → có thông tin để mở hồ sơ giảng viên; tác giả ngoài → null
            'teacher' => $d->author_id && $d->authorUser ? [
                'id' => $d->authorUser->id,
                'name' => $d->authorUser->name,
                'avatar' => $d->authorUser->avatar,
            ] : null,
            'topic_id' => $d->document_topic_id,
            'cover' => $d->cover_color?->value,
            'cover_url' => $d->coverUrl(),
            'access' => $this->access->conditions($d),
            'unlocked' => $this->access->canView($user, $d),
            'bought' => $purchases->has($d->id),
            'bought_at' => $purchases->get($d->id)?->purchased_at?->toIso8601String(),
            'saved' => in_array($d->id, $saved, true),
            'duration' => $d->durationLabel(),
            'published_at' => $d->published_at?->toIso8601String(),
        ])->values()->all();
    }
}
