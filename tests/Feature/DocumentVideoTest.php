<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\MembershipSource;
use App\Enums\UserRole;
use App\Enums\VideoStatus;
use App\Jobs\ProcessDocumentVideo;
use App\Models\Document;
use App\Models\User;
use App\Services\MembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Video tài liệu: ffmpeg cắt HLS 480p/720p mã hoá AES-128 → link có chữ ký, khoá kiểm tra quyền. */
class DocumentVideoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! (new Process(['ffmpeg', '-version']))->run() === 0) {
            $this->markTestSkipped('Cần ffmpeg');
        }
        Storage::fake('documents_local', ['serve' => true]);
    }

    private function sampleVideo(): string
    {
        $file = sys_get_temp_dir() . '/ellso-sample-' . uniqid() . '.mp4';
        (new Process([
            'ffmpeg', '-y', '-f', 'lavfi', '-i', 'testsrc=duration=4:size=1280x720:rate=24',
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=4', '-shortest',
            '-c:v', 'libx264', '-preset', 'ultrafast', '-c:a', 'aac', $file,
        ]))->mustRun();

        return $file;
    }

    public function test_transcode_and_signed_playback(): void
    {
        $member = User::factory()->create(['role' => UserRole::Student, 'is_active' => true]);
        $other = User::factory()->create(['role' => UserRole::Student, 'is_active' => true]);
        app(MembershipService::class)->grant($member, MembershipSource::Admin, 0);

        $file = $this->sampleVideo();
        Storage::disk('documents_local')->put('uploads/videos/a.mp4', file_get_contents($file));
        unlink($file);

        $doc = Document::create([
            'type' => DocumentType::Video, 'title' => 'Video thử', 'requires_membership' => true, 'status' => 'published',
            'video_source_path' => 'uploads/videos/a.mp4', 'video_status' => VideoStatus::Pending,
        ]);

        (new ProcessDocumentVideo($doc->id))->handle();
        $doc->refresh();

        $this->assertSame(VideoStatus::Ready, $doc->video_status, (string) $doc->video_error);
        $this->assertSame([480, 720], array_column($doc->hls_variants, 'height'));
        $this->assertSame(4, $doc->duration_seconds);
        $this->assertNull($doc->video_source_path);
        Storage::disk('documents_local')->assertMissing('uploads/videos/a.mp4');
        Storage::disk('documents_local')->assertExists($doc->hls_path . '/720/index.m3u8');
        $this->assertSame(16, strlen(base64_decode($doc->hls_key)));
        $this->assertStringNotContainsString(base64_decode($doc->hls_key), (string) $doc->getRawOriginal('hls_key')); // khoá được mã hoá trong DB

        // Học viên không có quyền
        $this->withToken($other->createToken('app', ['app'])->plainTextToken)
            ->postJson("/v1/documents/{$doc->id}/play")->assertForbidden()->assertJsonPath('code', 'locked');

        $this->app['auth']->forgetGuards();
        $play = $this->withToken($member->createToken('app', ['app'])->plainTextToken)
            ->postJson("/v1/documents/{$doc->id}/play")->assertOk()
            ->assertJsonPath('data.qualities', [480, 720])
            ->json('data');
        $this->withoutToken();

        // master → playlist từng chất lượng (link có chữ ký)
        $master = $this->get($play['url'])->assertOk()->assertHeader('Content-Type', 'application/vnd.apple.mpegurl')->getContent();
        $this->assertStringContainsString('RESOLUTION=1280x720', $master);
        preg_match_all('#^(http\S+)$#m', $master, $m);
        $this->assertCount(2, $m[1]);

        $variant = $this->get($m[1][1])->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/#EXT-X-KEY:METHOD=AES-128,URI="http[^"]+\/hls\/' . $doc->id . '\/key\?[^"]*signature=/', $variant);
        // Đoạn video: link tạm của ổ tài liệu (S3 presigned / local có chữ ký; ổ giả trong test có dạng riêng)
        $this->assertMatchesRegularExpression('#^http\S*/videos/' . $doc->id . '/\w+/720/seg_0000\.ts\?\S+$#m', $variant);

        // Khoá: đúng 16 byte; link sai chữ ký → 403; hết gói thành viên → 403
        preg_match('/URI="([^"]+)"/', $variant, $k);
        $key = $this->get(html_entity_decode($k[1]))->assertOk();
        $this->assertSame(base64_decode($doc->hls_key), $key->getContent());
        $this->get(str_replace('signature=', 'signature=x', $k[1]))->assertForbidden();

        $member->memberships()->update(['ends_at' => now()->subMinute()]);
        $this->get(html_entity_decode($k[1]))->assertForbidden();

        // Link master hết hạn sau 5 phút
        $this->travel(6)->minutes();
        $this->get($play['url'])->assertForbidden();
    }

    public function test_invalid_video_marks_failed(): void
    {
        Storage::disk('documents_local')->put('uploads/videos/bad.mp4', 'không phải video');
        $doc = Document::create(['type' => DocumentType::Video, 'title' => 'Lỗi', 'is_free' => true, 'video_source_path' => 'uploads/videos/bad.mp4', 'video_status' => VideoStatus::Pending]);

        (new ProcessDocumentVideo($doc->id))->handle();

        $this->assertSame(VideoStatus::Failed, $doc->fresh()->video_status);
        $this->assertNotEmpty($doc->fresh()->video_error);
    }
}
