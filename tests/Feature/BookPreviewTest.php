<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\UserRole;
use App\Enums\VideoStatus;
use App\Filament\Resources\Documents\Pages\CreateDocument;
use App\Filament\Resources\Documents\Pages\EditDocument;
use App\Models\Document;
use App\Models\User;
use App\Support\EpubPreview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use ZipArchive;

/** Sách: bản đọc thử (N trang PDF bằng qpdf / N chương EPUB), CMS, API. */
class BookPreviewTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents_local', ['serve' => true]);
        Storage::fake('public');
        $this->admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $this->student = User::factory()->create(['role' => UserRole::Student, 'is_active' => true]);
    }

    /** EPUB 3 tối giản: bìa (non-linear), nav, 5 chương, toc.ncx. */
    private function epub(int $chapters = 5): string
    {
        $path = tempnam(sys_get_temp_dir(), 'epub') . '.epub';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('mimetype', 'application/epub+zip');
        $zip->setCompressionName('mimetype', ZipArchive::CM_STORE);
        $zip->addFromString('META-INF/container.xml', '<?xml version="1.0"?><container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles><rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>');

        $items = '<item id="cover" href="cover.xhtml" media-type="application/xhtml+xml"/>'
            . '<item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>'
            . '<item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>'
            . '<item id="css" href="css/style.css" media-type="text/css"/>';
        $spine = '<itemref idref="cover" linear="no"/>';
        $navLis = '';
        $ncxPoints = '';
        foreach (range(1, $chapters) as $i) {
            $items .= "<item id=\"c{$i}\" href=\"text/ch{$i}.xhtml\" media-type=\"application/xhtml+xml\"/>";
            $spine .= "<itemref idref=\"c{$i}\"/>";
            $navLis .= "<li><a href=\"text/ch{$i}.xhtml#s\">Chương {$i}</a></li>";
            $ncxPoints .= "<navPoint id=\"p{$i}\" playOrder=\"{$i}\"><navLabel><text>Chương {$i}</text></navLabel><content src=\"text/ch{$i}.xhtml\"/></navPoint>";
            $zip->addFromString("OEBPS/text/ch{$i}.xhtml", "<?xml version=\"1.0\"?><html xmlns=\"http://www.w3.org/1999/xhtml\"><head><title>C{$i}</title></head><body><h1 id=\"s\">Nội dung chương {$i}</h1></body></html>");
        }

        $zip->addFromString('OEBPS/content.opf', '<?xml version="1.0"?><package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="id"><metadata xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:identifier id="id">x</dc:identifier><dc:title>Sách</dc:title><dc:language>vi</dc:language></metadata>'
            . "<manifest>{$items}</manifest><spine toc=\"ncx\">{$spine}</spine></package>");
        $zip->addFromString('OEBPS/cover.xhtml', '<?xml version="1.0"?><html xmlns="http://www.w3.org/1999/xhtml"><head><title>Bìa</title></head><body/></html>');
        $zip->addFromString('OEBPS/nav.xhtml', '<?xml version="1.0"?><html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops"><head><title>Mục lục</title></head><body><nav epub:type="toc"><ol>' . $navLis . '</ol></nav></body></html>');
        $zip->addFromString('OEBPS/toc.ncx', '<?xml version="1.0"?><ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1"><head/><docTitle><text>Sách</text></docTitle><navMap>' . $ncxPoints . '</navMap></ncx>');
        $zip->addFromString('OEBPS/css/style.css', 'body{}');
        $zip->close();

        return (string) file_get_contents($path);
    }

    /** PDF $pages trang, xref chính xác (qpdf đọc được). */
    private function pdf(int $pages): string
    {
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>', ''];
        $kids = [];
        foreach (range(1, $pages) as $i) {
            $content = "BT /F1 24 Tf 72 720 Td (Trang {$i}) Tj ET";
            $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents ' . (count($objects) + 2) . ' 0 R /Resources << >> >>';
            $kids[] = count($objects) . ' 0 R';
            $objects[] = '<< /Length ' . strlen($content) . " >>\nstream\n{$content}\nendstream";
        }
        $objects[1] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $pages . ' >>';

        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[] = strlen($out);
            $out .= ($i + 1) . " 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($out);
        $out .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $out .= sprintf("%010d 00000 n \n", $offset);
        }

        return $out . 'trailer << /Size ' . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    private function hasQpdf(): bool
    {
        return (new Process([config('ellso.documents.qpdf'), '--version']))->run() === 0;
    }

    private function api(?User $user = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken(($user ?? $this->student)->createToken('app', ['app'])->plainTextToken);
    }

    private function createBook(string $fileName, string $content, array $extra = []): Document
    {
        $this->actingAs($this->admin);
        Livewire::test(CreateDocument::class)
            ->fillForm([
                'type' => 'book', 'title' => 'Sách thử', 'sell' => true, 'price' => 99000, 'status' => 'published',
                'file_path' => UploadedFile::fake()->createWithContent($fileName, $content),
                ...$extra,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        return Document::where('title', 'Sách thử')->sole();
    }

    public function test_epub_preview_keeps_first_chapters_only(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'src') . '.epub';
        $target = tempnam(sys_get_temp_dir(), 'out') . '.epub';
        file_put_contents($source, $this->epub(12));

        $this->assertSame(12, EpubPreview::chapterCount($source));
        $this->assertSame(['kept' => 2, 'total' => 12], EpubPreview::make($source, $target, 2));

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($target) === true);
        $this->assertSame('mimetype', $zip->getNameIndex(0));
        $this->assertSame(ZipArchive::CM_STORE, $zip->statIndex(0)['comp_method']);
        $this->assertNotFalse($zip->getFromName('OEBPS/text/ch1.xhtml'));
        $this->assertNotFalse($zip->getFromName('OEBPS/text/ch2.xhtml'));
        $this->assertFalse($zip->getFromName('OEBPS/text/ch3.xhtml'));
        $this->assertFalse($zip->getFromName('OEBPS/text/ch5.xhtml'));
        $this->assertNotFalse($zip->getFromName('OEBPS/cover.xhtml'));       // bìa non-linear phía trước vẫn giữ
        $this->assertNotFalse($zip->getFromName('OEBPS/css/style.css'));

        $opf = $zip->getFromName('OEBPS/content.opf');
        $this->assertStringContainsString('text/ch2.xhtml', $opf);
        $this->assertStringNotContainsString('text/ch3.xhtml', $opf);
        $this->assertStringNotContainsString('idref="c3"', $opf);

        $nav = $zip->getFromName('OEBPS/nav.xhtml');
        $this->assertStringContainsString('Chương 2', $nav);
        $this->assertStringNotContainsString('Chương 3', $nav);
        $ncx = $zip->getFromName('OEBPS/toc.ncx');
        $this->assertStringContainsString('ch2.xhtml', $ncx);
        $this->assertStringNotContainsString('ch4.xhtml', $ncx);
        $zip->close();

        // Xin nhiều hơn 20% cuốn sách → chỉ lấy 20% (12 chương → 2); sách quá ngắn vẫn giữ ít nhất 1 chương
        $this->assertSame(['kept' => 2, 'total' => 12], EpubPreview::make($source, $target, 9));
        file_put_contents($source, $this->epub(3));
        $this->assertSame(['kept' => 1, 'total' => 3], EpubPreview::make($source, $target, 2));
    }

    public function test_cms_epub_book_preview_regenerate_and_toggle_off(): void
    {
        $book = $this->createBook('sach.epub', $this->epub(20));
        $this->assertTrue($book->preview_enabled);
        $this->assertSame(2, $book->preview_chapters);
        $this->assertSame('epub', $book->file_format);
        $this->assertSame(VideoStatus::Ready, $book->preview_status);
        $this->assertSame(2, $book->preview_count);
        $this->assertSame(20, $book->source_count);
        $first = $book->preview_path;
        Storage::disk('documents_local')->assertExists($first);

        // Đổi số chương → tạo lại, xoá bản cũ
        Livewire::test(EditDocument::class, ['record' => $book->getRouteKey()])
            ->assertSee('Bản đọc thử sẵn sàng · 2 chương đầu / 20 chương')
            ->assertActionVisible('regeneratePreview')
            ->fillForm(['preview_chapters' => 3])
            ->call('save')
            ->assertHasNoFormErrors();
        $book->refresh();
        $this->assertSame(3, $book->preview_count);
        $this->assertNotSame($first, $book->preview_path);
        Storage::disk('documents_local')->assertMissing($first);

        // Nhập quá 20% (20 chương → tối đa 4) → lấy 4, CMS ghi rõ
        Livewire::test(EditDocument::class, ['record' => $book->getRouteKey()])
            ->fillForm(['preview_chapters' => 9])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(4, $book->fresh()->preview_count);
        Livewire::test(EditDocument::class, ['record' => $book->getRouteKey()])
            ->assertSee('Bản đọc thử sẵn sàng · 4 chương đầu / 20 chương (giới hạn 20% cuốn sách)');

        // Tạo lại thủ công
        $second = $book->preview_path;
        Livewire::test(EditDocument::class, ['record' => $book->getRouteKey()])->callAction('regeneratePreview');
        $this->assertNotSame($second, $book->fresh()->preview_path);
        Storage::disk('documents_local')->assertMissing($second);

        // Tắt đọc thử → xoá file
        $path = $book->fresh()->preview_path;
        Livewire::test(EditDocument::class, ['record' => $book->getRouteKey()])
            ->fillForm(['preview_enabled' => false])
            ->call('save')
            ->assertHasNoFormErrors();
        $book->refresh();
        $this->assertNull($book->preview_path);
        $this->assertNull($book->preview_status);
        Storage::disk('documents_local')->assertMissing($path);
        Livewire::test(EditDocument::class, ['record' => $book->getRouteKey()])->assertActionHidden('regeneratePreview');
    }

    public function test_cms_pdf_book_preview_with_qpdf(): void
    {
        if (! $this->hasQpdf()) {
            $this->markTestSkipped('Cần qpdf');
        }

        $book = $this->createBook('sach.pdf', $this->pdf(60));
        $this->assertSame('pdf', $book->file_format);
        $this->assertSame(VideoStatus::Ready, $book->preview_status, (string) $book->preview_error);
        $this->assertSame(10, $book->preview_count);
        $this->assertSame(60, $book->source_count);

        $tmp = tempnam(sys_get_temp_dir(), 'pv') . '.pdf';
        file_put_contents($tmp, Storage::disk('documents_local')->get($book->preview_path));
        $pages = new Process([config('ellso.documents.qpdf'), '--show-npages', $tmp]);
        $pages->run();
        $this->assertSame('10', trim($pages->getOutput()));

        // Nhập nhiều hơn 20% (60 trang → tối đa 12) → lấy 12, CMS ghi rõ
        Livewire::test(EditDocument::class, ['record' => $book->getRouteKey()])
            ->fillForm(['preview_pages' => 30])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(12, $book->fresh()->preview_count);
        Livewire::test(EditDocument::class, ['record' => $book->getRouteKey()])
            ->assertSee('12 trang đầu / 60 trang (giới hạn 20% cuốn sách)');
    }

    public function test_pdf_that_qpdf_cannot_read_marks_failed(): void
    {
        $book = $this->createBook('hong.pdf', '%PDF-1.4 không phải pdf thật');
        $this->assertSame(VideoStatus::Failed, $book->preview_status);
        $this->assertNotEmpty($book->preview_error);
        $this->assertNull($book->preview_path);
    }

    public function test_api_preview_for_non_buyer_and_no_preview(): void
    {
        $book = $this->createBook('sach.epub', $this->epub(10));

        // Học viên chưa mua: sách khoá nhưng mở được bản đọc thử
        $item = $this->api()->getJson("/v1/documents/{$book->id}")->assertOk()->json('data');
        $this->assertFalse($item['unlocked']);
        $this->assertSame(['available' => true, 'chapters' => 2], $item['preview']);
        $this->api()->postJson("/v1/documents/{$book->id}/open")->assertForbidden()->assertJsonPath('code', 'locked');

        $preview = $this->api()->postJson("/v1/documents/{$book->id}/preview")->assertOk()->json('data');
        $this->assertSame('epub', $preview['format']);
        $this->assertSame(2, $preview['chapters']);
        $this->assertArrayNotHasKey('available', $preview);
        $this->assertStringContainsString($book->fresh()->preview_path, $preview['url']); // link tạm tới file đọc thử, không phải file sách
        $this->assertStringNotContainsString($book->file_path, $preview['url']);

        // Trình đọc: trang HTML + file đọc thử qua link có chữ ký (đúng nội dung bản đọc thử, sửa chữ ký → 403)
        $this->assertStringContainsString('/v1/reader?', $preview['reader_url']);
        $this->get($preview['reader_url'])->assertOk()->assertSee('epub-0.3.93.min.js', false);
        parse_str((string) parse_url($preview['reader_url'], PHP_URL_QUERY), $q);
        $this->assertStringContainsString('/v1/reader/file/' . $book->id . '/preview', $q['file']);
        $file = $this->get($q['file'])->assertOk()->assertHeader('Content-Type', 'application/epub+zip');
        $this->assertSame(Storage::disk('documents_local')->get($book->fresh()->preview_path), $file->streamedContent());
        $this->get($q['file'] . 'x')->assertForbidden();
        $this->get('/v1/reader/file/' . $book->id . '/full')->assertForbidden();

        // Video / PDF: preview null; sách tắt đọc thử → 404 no_preview
        $pdf = Document::create(['type' => DocumentType::Pdf, 'title' => 'PDF', 'is_free' => true, 'status' => 'published', 'file_path' => 'files/pdf/a.pdf', 'file_format' => 'pdf']);
        $this->assertNull($this->api()->getJson("/v1/documents/{$pdf->id}")->json('data.preview'));
        $this->api()->postJson("/v1/documents/{$pdf->id}/preview")->assertNotFound()->assertJsonPath('code', 'no_preview');

        $book->update(['preview_enabled' => false]);
        $this->assertSame(['available' => false, 'chapters' => null], $this->api()->getJson("/v1/documents/{$book->id}")->json('data.preview'));
        $this->api()->postJson("/v1/documents/{$book->id}/preview")->assertNotFound()->assertJsonPath('code', 'no_preview');
    }

    public function test_deleting_book_deletes_preview(): void
    {
        $book = $this->createBook('sach.epub', $this->epub(3));
        $path = $book->preview_path;
        Storage::disk('documents_local')->assertExists($path);

        $book->delete();
        Storage::disk('documents_local')->assertMissing($path);
    }
}
