<?php

namespace Tests\Feature;

use App\Enums\CourseStatus;
use App\Enums\EnrollmentSource;
use App\Enums\UnlockMode;
use App\Enums\UserRole;
use App\Enums\VideoStatus;
use App\Filament\Resources\Lessons\Pages\EditLessonContent;
use App\Jobs\ProcessLessonVideo;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonVideo;
use App\Models\PathChapter;
use App\Models\PathLesson;
use App\Models\User;
use App\Services\LearningProgress;
use App\Support\LessonContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Khối Video trong bài học: tải video riêng cho bài, cắt HLS, mốc thời gian, chặn xuất bản, phát có kiểm tra quyền. */
class LessonVideoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $student;

    private Lesson $lesson;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents_local', ['serve' => true]);
        $this->admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $this->student = User::factory()->create(['role' => UserRole::Student, 'is_active' => true]);

        $this->course = Course::create(['title' => 'Web AI', 'instructor_id' => $this->admin->id, 'price' => 0, 'status' => CourseStatus::Published]);
        $chapter = PathChapter::create(['learning_path_id' => $this->course->defaultPath->id, 'title' => 'Chương 1', 'sort_order' => 1]);
        $this->lesson = Lesson::create(['title' => 'Bài video', 'owner_id' => $this->admin->id]);
        PathLesson::create(['path_chapter_id' => $chapter->id, 'lesson_id' => $this->lesson->id, 'sort_order' => 1, 'unlock_mode' => UnlockMode::AfterPrevious]);
    }

    private function readyVideo(int $duration = 600): LessonVideo
    {
        return $this->lesson->videos()->create([
            'original_name' => 'demo.mp4', 'video_status' => VideoStatus::Ready, 'hls_path' => 'lesson-videos/x/1/abc',
            'hls_key' => base64_encode(random_bytes(16)), 'hls_variants' => [['height' => 480, 'width' => 854, 'bandwidth' => 900000]],
            'duration_seconds' => $duration,
        ]);
    }

    private function content(?int $videoId, string $chapters = ''): array
    {
        return ['blocks' => [
            ['id' => 'p1', 'type' => 'paragraph', 'data' => ['text' => 'Mở đầu']],
            ['id' => 'v1', 'type' => 'video', 'data' => ['video_id' => $videoId, 'caption' => '<b>Demo</b> nhanh', 'chapters' => $chapters]],
        ]];
    }

    public function test_sanitize_and_publish_checks(): void
    {
        $video = $this->readyVideo();
        $other = Lesson::create(['title' => 'Bài khác', 'owner_id' => $this->admin->id]);
        $foreign = $other->videos()->create(['video_status' => VideoStatus::Ready]);

        // Video của bài khác bị bỏ id; chú thích bỏ HTML
        $clean = LessonContent::sanitize($this->content($foreign->id), '/storage', [$video->id]);
        $this->assertNull($clean['blocks'][1]['data']['video_id']);
        $clean = LessonContent::sanitize($this->content($video->id, "00:00 A\n05:00 B"), '/storage', [$video->id]);
        $this->assertSame(['video_id' => $video->id, 'caption' => 'Demo nhanh', 'chapters' => "00:00 A\n05:00 B"], $clean['blocks'][1]['data']);

        $videos = $this->lesson->videos()->get()->keyBy('id')->all();
        $this->assertSame(['Video 1: chưa tải video lên.'], LessonContent::videoProblems($this->content(null), $videos));
        $this->assertSame([], LessonContent::videoProblems($this->content($video->id, "00:00 A\n05:00 B"), $videos));
        $this->assertStringContainsString('vượt quá độ dài video (10:00)', implode(' ', LessonContent::videoProblems($this->content($video->id, '12:00 Quá'), $videos)));

        $video->update(['video_status' => VideoStatus::Processing]);
        $this->assertSame(['Video 1: đang xử lý, chờ xong rồi xuất bản.'], LessonContent::videoProblems($this->content($video->id), $this->lesson->videos()->get()->keyBy('id')->all()));
    }

    public function test_cms_upload_status_and_publish(): void
    {
        Queue::fake();
        $this->actingAs($this->admin);

        $page = Livewire::test(EditLessonContent::class, ['record' => $this->lesson->getRouteKey()])
            ->set('editorVideo', UploadedFile::fake()->create('bai-giang.mp4', 500, 'video/mp4'));
        $state = $page->instance()->storeEditorVideo();

        $video = LessonVideo::sole();
        $this->assertSame(['id' => $video->id, 'status' => 'pending', 'label' => 'Chờ xử lý', 'duration' => null, 'name' => 'bai-giang.mp4', 'error' => null], $state);
        Storage::disk('documents_local')->assertExists($video->video_source_path);
        Queue::assertPushed(ProcessLessonVideo::class, fn ($job) => $job->videoId === $video->id);
        $this->assertSame('pending', $page->instance()->videoState($video->id)['status']);

        // Lưu nháp với khối video; xuất bản bị chặn khi video chưa xử lý xong
        $page->call('saveDraft', $this->content($video->id, '00:00 Giới thiệu'));
        $this->assertSame($video->id, $this->lesson->fresh()->content_draft['blocks'][1]['data']['video_id']);
        $page->callAction('publish');
        $this->assertNull($this->lesson->fresh()->published_at);

        $video->update(['video_status' => VideoStatus::Ready, 'hls_path' => 'lesson-videos/1/1/x', 'duration_seconds' => 300, 'video_source_path' => null]);
        $page->callAction('publish');
        $this->assertNotNull($this->lesson->fresh()->published_at);

        // Video không còn dùng (quá 1 giờ) bị xoá khi lưu
        $orphan = $this->lesson->videos()->create(['video_status' => VideoStatus::Ready]);
        $orphan->forceFill(['created_at' => now()->subHours(2)])->save();
        $page->call('saveDraft', $this->content($video->id));
        $this->assertNull(LessonVideo::find($orphan->id));
        $this->assertNotNull(LessonVideo::find($video->id));
    }

    public function test_app_content_and_playback_access(): void
    {
        try {
            $hasFfmpeg = (new Process(['ffmpeg', '-version']))->run() === 0;
        } catch (\Throwable) {
            $hasFfmpeg = false;
        }
        if (! $hasFfmpeg) {
            $this->markTestSkipped('Cần ffmpeg');
        }

        // Video thật 4 giây → cắt HLS
        $file = sys_get_temp_dir() . '/ellso-lesson-' . uniqid() . '.mp4';
        (new Process([
            'ffmpeg', '-y', '-f', 'lavfi', '-i', 'testsrc=duration=4:size=640x360:rate=24',
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=4', '-shortest',
            '-c:v', 'libx264', '-preset', 'ultrafast', '-c:a', 'aac', $file,
        ]))->mustRun();
        Storage::disk('documents_local')->put('uploads/lesson-videos/a.mp4', file_get_contents($file));
        unlink($file);
        $video = $this->lesson->videos()->create(['video_source_path' => 'uploads/lesson-videos/a.mp4', 'video_status' => VideoStatus::Pending]);

        (new ProcessLessonVideo($video->id))->handle();
        $video->refresh();
        $this->assertSame(VideoStatus::Ready, $video->video_status, (string) $video->video_error);
        $this->assertSame([480], array_column($video->hls_variants, 'height')); // 360p gốc → không phóng to lên 720
        $this->assertSame(4, $video->duration_seconds);
        Storage::disk('documents_local')->assertMissing('uploads/lesson-videos/a.mp4');

        $this->lesson->update(['content_draft' => $this->content($video->id, "00:00 Mở đầu\n00:02 Phần chính")]);
        $this->lesson->publish();

        // Chưa ghi danh → không xem được
        $token = $this->student->createToken('app', ['app'])->plainTextToken;
        $this->withToken($token)->postJson("/v1/lessons/{$this->lesson->id}/videos/{$video->id}/play")->assertForbidden();

        app(LearningProgress::class)->enroll($this->student, $this->course, EnrollmentSource::Admin, $this->course->defaultPath);
        $this->app['auth']->forgetGuards();
        $block = $this->withToken($token)->getJson("/v1/lessons/{$this->lesson->id}")->assertOk()->json('data.content.blocks.1');
        $this->assertSame('video', $block['type']);
        $this->assertSame([
            'video_id' => $video->id,
            'duration_seconds' => 4,
            'caption' => 'Demo nhanh',
            'chapters' => [['start' => 0, 'time' => '00:00', 'title' => 'Mở đầu'], ['start' => 2, 'time' => '00:02', 'title' => 'Phần chính']],
        ], $block['data']);

        $play = $this->withToken($token)->postJson("/v1/lessons/{$this->lesson->id}/videos/{$video->id}/play")->assertOk()
            ->assertJsonPath('data.qualities', [480])->json('data');
        $this->withoutToken();

        $master = $this->get($play['url'])->assertOk()->getContent();
        preg_match('#^(http\S+)$#m', $master, $m);
        $variant = $this->get($m[1])->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#/hls/lesson-videos/' . $video->id . '/key\?#', $variant);
        preg_match('/URI="([^"]+)"/', $variant, $k);
        $this->assertSame(base64_decode($video->hls_key), $this->get(html_entity_decode($k[1]))->assertOk()->getContent());

        // Gỡ video khỏi nội dung đã xuất bản → khoá không trả nữa
        $this->lesson->update(['content_draft' => ['blocks' => [['id' => 'p1', 'type' => 'paragraph', 'data' => ['text' => 'Hết video']]]]]);
        $this->lesson->publish();
        $this->get(html_entity_decode($k[1]))->assertForbidden();

        // Nhân bản bài (khi còn video trong nháp) → bản sao dùng chung file đã xử lý; xoá bản sao không xoá file của bài gốc
        $this->lesson->update(['content_draft' => $this->content($video->id)]);
        $copy = $this->lesson->duplicate();
        $copyVideoId = $copy->content_draft['blocks'][1]['data']['video_id'];
        $this->assertNotSame($video->id, $copyVideoId);
        $this->assertSame($video->hls_path, LessonVideo::find($copyVideoId)->hls_path);
        LessonVideo::find($copyVideoId)->delete();
        Storage::disk('documents_local')->assertExists($video->hls_path . '/480/index.m3u8');
        $video->delete();
        Storage::disk('documents_local')->assertMissing($video->hls_path . '/480/index.m3u8');
    }
}
