<?php

namespace Tests\Feature;

use App\Rules\DocumentFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Tests\TestCase;
use ZipArchive;

/** DocumentFile đọc được file tạm nằm trên storage từ xa (production: S3 BizFly — getRealPath() chỉ là key). */
class DocumentFileRuleTest extends TestCase
{
    private function remote(string $name, string $contents): TemporaryUploadedFile
    {
        Storage::fake('remote_tmp');
        Storage::disk('remote_tmp')->put("livewire-tmp/{$name}", $contents);

        // Giả lập S3: đường dẫn "thật" không tồn tại trên máy, chỉ đọc được qua storage
        return new class($name, 'remote_tmp', $name) extends TemporaryUploadedFile {
            public function __construct($path, $disk, private string $original)
            {
                parent::__construct($path, $disk);
            }

            public function getRealPath(): string
            {
                return 'livewire-tmp/khong-co-tren-may';
            }

            public function getClientOriginalName(): string
            {
                return $this->original;
            }
        };
    }

    private function passes(TemporaryUploadedFile $file): bool
    {
        return Validator::make(['f' => $file], ['f' => [new DocumentFile(['pdf', 'epub'])]])->passes();
    }

    public function test_reads_remote_temporary_files(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ep');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('mimetype', 'application/epub+zip');
        $zip->addFromString('META-INF/container.xml', '<container/>');
        $zip->close();

        $this->assertTrue($this->passes($this->remote('sach.epub', file_get_contents($path))));
        $this->assertTrue($this->passes($this->remote('tai-lieu.pdf', '%PDF-1.7 nội dung')));
        $this->assertFalse($this->passes($this->remote('gia.epub', 'không phải zip')));
        $this->assertFalse($this->passes($this->remote('gia.pdf', 'hello')));
        unlink($path);
    }
}
