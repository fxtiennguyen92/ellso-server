<?php

namespace Tests\Feature;

use App\Support\LessonContent;
use App\Support\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Cấu hình production (CloudFront + S3) — local giữ nguyên như cũ. */
class ProductionSetupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_probe', fn (Request $request) => [
            'ip' => $request->ip(),
            'secure' => $request->isSecure(),
            'host' => $request->getHost(),
            'url' => url('/x'),
        ]);
    }

    public function test_local_defaults_unchanged(): void
    {
        $this->get('/_probe', ['X-Forwarded-For' => '9.9.9.9'])
            ->assertJson(['ip' => '127.0.0.1', 'secure' => false])
            ->assertJsonPath('url', 'http://localhost/x');

        $this->assertSame('public', MediaStorage::diskName());
        $this->assertSame('public', MediaStorage::uploadVisibility());
    }

    public function test_behind_cloudfront_uses_real_ip_and_https(): void
    {
        config(['app.trusted_proxies' => 'all', 'app.force_https' => true]);

        $this->withServerVariables(['REMOTE_ADDR' => '130.176.1.1'])
            ->get('http://cms.ellso.test/_probe', [
                'X-Forwarded-For' => '1.1.1.1, 113.161.2.3', // 1.1.1.1 do người dùng tự gửi, CloudFront nối IP thật vào cuối
                'X-Forwarded-Host' => 'evil.test',
                'X-Forwarded-Proto' => 'http',
            ])
            ->assertJson(['ip' => '113.161.2.3', 'secure' => true, 'host' => 'cms.ellso.test'])
            ->assertJsonPath('url', 'https://cms.ellso.test/x');
    }

    public function test_media_on_s3_links_through_cloudfront(): void
    {
        config([
            'ellso.media.disk' => 'media_s3',
            'filesystems.disks.media_s3.bucket' => 'ellso-files-test',
            'filesystems.disks.media_s3.region' => 'ap-southeast-1',
            'filesystems.disks.media_s3.key' => 'test',
            'filesystems.disks.media_s3.secret' => 'test',
            'filesystems.disks.media_s3.url' => 'https://media.ellso.test',
        ]);
        Storage::forgetDisk('media_s3');

        $this->assertTrue(MediaStorage::isS3());
        $this->assertSame('private', MediaStorage::uploadVisibility());
        $this->assertSame('https://media.ellso.test/media/campaigns/a.jpg', MediaStorage::url('campaigns/a.jpg'));
        $this->assertSame('https://media.ellso.test/media/', MediaStorage::baseUrl());

        // Ảnh trong bài học: link cũ /storage/… (soạn trên local) và link S3 đều trả về link CloudFront
        $content = LessonContent::forApp(['blocks' => [
            ['id' => 'a', 'type' => 'image', 'data' => ['file' => ['url' => 'http://localhost:8000/storage/lessons/content/1/x.jpg']]],
            ['id' => 'b', 'type' => 'image', 'data' => ['file' => ['url' => 'https://old-cdn.test/media/lessons/content/1/y.jpg']]],
            ['id' => 'c', 'type' => 'image', 'data' => ['file' => ['url' => 'https://example.com/z.jpg']]],
        ]]);

        $this->assertSame('https://media.ellso.test/media/lessons/content/1/x.jpg', $content['blocks'][0]['data']['file']['url']);
        $this->assertSame('https://media.ellso.test/media/lessons/content/1/y.jpg', $content['blocks'][1]['data']['file']['url']);
        $this->assertSame('https://example.com/z.jpg', $content['blocks'][2]['data']['file']['url']);
    }
}
