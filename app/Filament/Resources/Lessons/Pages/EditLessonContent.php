<?php

namespace App\Filament\Resources\Lessons\Pages;

use App\Filament\Resources\Lessons\LessonResource;
use App\Models\Lesson;
use App\Enums\VideoStatus;
use App\Jobs\ProcessLessonVideo;
use App\Support\DocumentStorage;
use App\Support\LessonContent;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use App\Support\MediaStorage;

/**
 * Soạn nội dung bài học bằng Editor.js.
 * - Tự lưu bản nháp (content_draft) sau khi ngừng gõ; nút "Lưu nháp" để lưu ngay.
 * - "Xuất bản" lưu nháp trước rồi mới chép sang bản đã xuất bản.
 * - Ảnh tải lên qua Livewire → public disk: lessons/content/{id}/…
 * - Video tải lên qua Livewire → ổ tài liệu (riêng tư) → job cắt HLS (LessonVideo). Video không còn dùng trong
 *   bản nháp lẫn bản đã xuất bản thì bị xoá khi lưu.
 */
class EditLessonContent extends Page
{
    use InteractsWithRecord;

    protected static string $resource = LessonResource::class;

    protected string $view = 'filament.lessons.edit-content';

    /** File ảnh tạm do Editor.js gửi lên (qua $wire.upload). */
    public ?TemporaryUploadedFile $editorImage = null;

    /** File video tạm do khối Video gửi lên (qua $wire.upload). */
    public ?TemporaryUploadedFile $editorVideo = null;

    public function mount(int | string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->authorizeEdit();
    }

    public function getTitle(): string | Htmlable
    {
        return 'Nội dung: ' . $this->lesson()->title;
    }

    public function getBreadcrumb(): ?string
    {
        return 'Nội dung';
    }

    // ── Gọi từ Editor.js ─────────────────────────────────────

    /** Lưu bản nháp (Editor.js output). */
    public function saveDraft(array $data): void
    {
        $this->authorizeEdit();

        $lesson = $this->lesson();
        $lesson->update([
            'content_draft' => LessonContent::sanitize($data, $this->imageUrlPrefix(), $lesson->videos()->pluck('id')->map(fn ($id) => (int) $id)->all()),
        ]);
        $this->pruneVideos();
    }

    /** Nhận video vừa upload: chuyển sang ổ tài liệu, tạo LessonVideo, đưa vào hàng đợi cắt HLS. */
    public function storeEditorVideo(): ?array
    {
        $this->authorizeEdit();

        $maxMb = (int) config('ellso.documents.video_max_upload_mb');
        $this->validate([
            'editorVideo' => ['required', 'file', 'mimetypes:video/mp4,video/quicktime,video/x-matroska,video/webm', 'max:' . ($maxMb * 1024)],
        ], [
            'editorVideo.mimetypes' => 'Chỉ nhận video MP4, MOV, MKV hoặc WebM.',
            'editorVideo.max' => "Video tối đa {$maxMb} MB.",
        ]);

        $lesson = $this->lesson();
        $name = $this->editorVideo->getClientOriginalName();
        $path = $this->editorVideo->storeAs(
            'uploads/lesson-videos/' . $lesson->id,
            Str::lower(Str::random(20)) . '.' . ($this->editorVideo->getClientOriginalExtension() ?: 'mp4'),
            DocumentStorage::diskName(),
        );
        $this->editorVideo = null;
        if (! $path) {
            return null;
        }

        $video = $lesson->videos()->create([
            'original_name' => mb_substr($name, 0, 255),
            'video_source_path' => $path,
            'video_status' => VideoStatus::Pending,
            'uploaded_by_id' => auth()->id(),
        ]);
        ProcessLessonVideo::dispatch($video->id);

        return $video->editorState();
    }

    /** Trạng thái xử lý video (khối Video hỏi định kỳ đến khi Sẵn sàng / Lỗi). */
    public function videoState(int $id): ?array
    {
        $this->authorizeEdit();

        return $this->lesson()->videos()->find($id)?->editorState();
    }

    /** Xoá video không còn nằm trong bản nháp lẫn bản đã xuất bản (bỏ qua video vừa tải trong 1 giờ — có thể chưa lưu). */
    private function pruneVideos(): void
    {
        $lesson = $this->lesson();
        $used = array_unique([
            ...LessonContent::videoIds($lesson->content_draft),
            ...LessonContent::videoIds($lesson->content_published),
        ]);
        $lesson->videos()
            ->whereNotIn('id', $used ?: [0])
            ->where('created_at', '<', now()->subHour())
            ->get()
            ->each->delete();
    }

    /** Lưu ảnh vừa upload, trả URL công khai cho Editor.js. */
    public function storeEditorImage(): ?string
    {
        $this->authorizeEdit();

        $this->validate([
            'editorImage' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ], [
            'editorImage.image' => 'File phải là ảnh.',
            'editorImage.mimes' => 'Chỉ nhận ảnh JPG, PNG, WebP hoặc GIF.',
            'editorImage.max' => 'Ảnh tối đa 5 MB.',
        ]);

        $path = $this->editorImage->store('lessons/content/' . $this->lesson()->id, MediaStorage::diskName());
        $this->editorImage = null;

        return $path ? MediaStorage::disk()->url($path) : null;
    }

    // ── Actions ──────────────────────────────────────────────

    /** Mở từ nút "Xuất bản" trong view (sau khi đã lưu nháp). */
    public function publishAction(): Action
    {
        return Action::make('publish')
            ->label('Xuất bản')
            ->icon(Heroicon::RocketLaunch)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Xuất bản nội dung?')
            ->modalDescription(fn () => trim((LessonResource::usageWarning($this->lesson()) ?? '') . ' Học viên sẽ thấy nội dung hiện tại của bản nháp.'))
            ->modalSubmitActionLabel('Xuất bản')
            ->action(function () {
                $this->authorizeEdit();
                $lesson = $this->lesson()->refresh();

                if (! $lesson->hasDraftContent()) {
                    Notification::make()->title('Bài chưa có nội dung')->warning()->send();

                    return;
                }

                // Câu hỏi trắc nghiệm phải hợp lệ thì mới chấm được; video phải xử lý xong → chặn xuất bản
                if ($problems = [
                    ...LessonContent::quizProblems($lesson->content_draft),
                    ...LessonContent::videoProblems($lesson->content_draft, $lesson->videos()->get()->keyBy('id')->all()),
                ]) {
                    Notification::make()
                        ->title('Chưa xuất bản: nội dung chưa hoàn chỉnh')
                        ->body(implode("\n", $problems))
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                $lesson->publish();
                $this->pruneVideos();
                Notification::make()->title('Đã xuất bản nội dung')->success()->send();
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('Về thư viện bài học')
                ->icon(Heroicon::ArrowUturnLeft)
                ->color('gray')
                ->url(LessonResource::getUrl('index')),
        ];
    }

    // ── Hỗ trợ ───────────────────────────────────────────────

    public function lesson(): Lesson
    {
        /** @var Lesson */
        return $this->getRecord();
    }

    /** Chỉ nhận ảnh nằm trên ổ ảnh của hệ thống (MediaStorage). */
    private function imageUrlPrefix(): string
    {
        return MediaStorage::baseUrl();
    }

    private function authorizeEdit(): void
    {
        abort_unless(auth()->user()->can('update', $this->getRecord()), 403);
    }
}
