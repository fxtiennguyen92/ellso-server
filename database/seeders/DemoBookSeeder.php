<?php

namespace Database\Seeders;

use App\Enums\ChapterColor;
use App\Enums\DocumentType;
use App\Enums\UserRole;
use App\Jobs\GenerateBookPreview;
use App\Models\Document;
use App\Models\DocumentTopic;
use App\Models\User;
use App\Support\DocumentStorage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * 6 cuốn sách MẪU có nội dung thật (file EPUB tạo bằng PHP, đọc được trong trình đọc của app):
 * 2 miễn phí, 2 dành cho gói thành viên, 2 bán lẻ (có bản đọc thử chương đầu).
 * Chạy tay:  php artisan db:seed --class=DemoBookSeeder   (chạy lại sẽ thay sách mẫu cùng tên)
 */
class DemoBookSeeder extends Seeder
{
    private const BOOKS = [
        [
            'title' => 'Giao tiếp không áp lực',
            'author' => 'Trần Hải Yến',
            'topic' => 'Kỹ năng mềm',
            'color' => ChapterColor::Sage,
            'access' => 'free',
            'description' => 'Cẩm nang ngắn giúp bạn tự tin bắt chuyện, lắng nghe và nói điều mình muốn mà không làm mất lòng ai.',
            'chapters' => ['Vì sao ta ngại giao tiếp', 'Lắng nghe trước khi nói', 'Đặt câu hỏi mở', 'Nói "không" khéo léo', 'Luyện tập mỗi ngày'],
        ],
        [
            'title' => 'Quản lý thời gian cho người bận rộn',
            'author' => 'Phạm Quốc Bảo',
            'topic' => 'Năng suất',
            'color' => ChapterColor::Teal,
            'access' => 'free',
            'description' => 'Những nguyên tắc đơn giản để làm ít hơn nhưng hiệu quả hơn, áp dụng được ngay từ tuần này.',
            'chapters' => ['Thời gian đi đâu mất', 'Ma trận ưu tiên', 'Khối thời gian tập trung', 'Nói không với việc vặt', 'Tổng kết tuần'],
        ],
        [
            'title' => 'Tư duy phản biện thực hành',
            'author' => 'Nguyễn Thu Hà',
            'topic' => 'Tư duy',
            'color' => ChapterColor::Indigo,
            'access' => 'membership',
            'description' => 'Cách đặt câu hỏi, kiểm tra lập luận và ra quyết định dựa trên dữ kiện thay vì cảm tính.',
            'chapters' => ['Phản biện không phải phản đối', 'Tách sự thật và ý kiến', 'Các lỗi lập luận thường gặp', 'Ra quyết định có cơ sở', 'Tranh luận văn minh'],
        ],
        [
            'title' => 'Thuyết trình tự tin',
            'author' => 'Trần Hải Yến',
            'topic' => 'Kỹ năng mềm',
            'color' => ChapterColor::Plum,
            'access' => 'membership',
            'description' => 'Từ chuẩn bị nội dung, thiết kế slide đến làm chủ sân khấu và trả lời câu hỏi khó.',
            'chapters' => ['Bắt đầu từ người nghe', 'Cấu trúc 3 phần', 'Slide ít chữ', 'Giọng nói và ánh mắt', 'Xử lý câu hỏi khó'],
        ],
        [
            'title' => 'Marketing cho người mới bắt đầu',
            'author' => 'Phạm Quốc Bảo',
            'topic' => 'Kinh doanh',
            'color' => ChapterColor::Terracotta,
            'access' => 'paid',
            'price' => 99_000,
            'description' => 'Hiểu khách hàng, chọn kênh phù hợp và đo lường hiệu quả với ngân sách nhỏ.',
            'chapters' => ['Khách hàng của bạn là ai', 'Thông điệp cốt lõi', 'Chọn kênh phù hợp', 'Nội dung đều đặn', 'Đo lường và cải thiện'],
        ],
        [
            'title' => 'Lãnh đạo nhóm nhỏ',
            'author' => 'Nguyễn Thu Hà',
            'topic' => 'Quản lý',
            'color' => ChapterColor::Ochre,
            'access' => 'paid',
            'price' => 129_000,
            'description' => 'Dành cho trưởng nhóm mới: giao việc rõ ràng, họp 1:1 hiệu quả và phản hồi để mọi người cùng tiến bộ.',
            'chapters' => ['Từ nhân viên lên trưởng nhóm', 'Giao việc với 5W1H', 'Họp 1:1 trong 15 phút', 'Phản hồi đúng cách', 'Xây dựng niềm tin'],
        ],
    ];

    public function run(): void
    {
        if (app()->isProduction() && ! config('ellso.allow_demo_seed')) {
            throw new RuntimeException('Không chạy dữ liệu demo trên production (đặt ALLOW_DEMO_SEED=true để cho phép tạm thời).');
        }

        Document::withTrashed()->whereIn('title', array_column(self::BOOKS, 'title'))->get()->each->forceDelete();
        $teachers = User::where('role', UserRole::Instructor)->pluck('id', 'name');

        foreach (self::BOOKS as $i => $b) {
            $topic = DocumentTopic::firstOrCreate(['name' => $b['topic']], ['sort_order' => $i]);
            $path = 'books/demo/'.Str::slug($b['title']).'.epub';
            $size = $this->writeEpub($path, $b);
            $teacherId = $teachers[$b['author']] ?? null;

            $doc = Document::create([
                'type' => DocumentType::Book,
                'title' => $b['title'],
                'author_id' => $teacherId,
                'author' => $teacherId ? null : $b['author'],
                'description' => $b['description'],
                'document_topic_id' => $topic->id,
                'cover_color' => $b['color'],
                'is_free' => $b['access'] === 'free',
                'requires_membership' => $b['access'] === 'membership',
                'price' => $b['price'] ?? null,
                'allow_download' => $b['access'] === 'paid',
                'preview_enabled' => $b['access'] !== 'free',
                'preview_chapters' => 1,
                'file_path' => $path,
                'file_name' => Str::slug($b['title']).'.epub',
                'file_format' => 'epub',
                'file_size' => $size,
                'status' => 'published',
                'published_at' => now()->subDays(6 - $i),
            ]);

            // Bản đọc thử (chương đầu) tạo ngay, không cần chạy hàng đợi
            if ($doc->preview_enabled) {
                (new GenerateBookPreview($doc->id))->handle();
            }
        }
    }

    /** Tạo file EPUB 3 (kèm toc.ncx cho trình đọc cũ) lên ổ tài liệu riêng tư. Trả kích thước file. */
    private function writeEpub(string $path, array $book): int
    {
        $tmp = storage_path('app/tmp/demo-book-'.Str::random(8).'.epub');
        File::ensureDirectoryExists(dirname($tmp));
        $e = fn (string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $id = 'urn:uuid:'.Str::uuid();

        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('mimetype', 'application/epub+zip');
        $zip->setCompressionName('mimetype', ZipArchive::CM_STORE);
        $zip->addFromString('META-INF/container.xml', '<?xml version="1.0" encoding="UTF-8"?><container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles><rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>');
        $zip->addFromString('OEBPS/style.css', 'body{font-family:serif;line-height:1.6;margin:0 5%}h1{font-size:1.5em;margin:1em 0 .6em}p{margin:0 0 .9em;text-align:justify}blockquote{margin:1em 0;padding-left:1em;border-left:3px solid #10b981;font-style:italic}li{margin-bottom:.4em}');

        $manifest = $spine = $nav = $ncx = '';
        foreach ($book['chapters'] as $n => $title) {
            $no = $n + 1;
            $file = "chapter-{$no}.xhtml";
            $body = $this->chapterBody($book['title'], $title, $no, count($book['chapters']));
            $zip->addFromString("OEBPS/{$file}", '<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE html><html xmlns="http://www.w3.org/1999/xhtml" xml:lang="vi" lang="vi"><head><meta charset="utf-8"/><title>'.$e($title).'</title><link rel="stylesheet" href="style.css"/></head><body><h1>Chương '.$no.'. '.$e($title).'</h1>'.$body.'</body></html>');
            $manifest .= "<item id=\"c{$no}\" href=\"{$file}\" media-type=\"application/xhtml+xml\"/>";
            $spine .= "<itemref idref=\"c{$no}\"/>";
            $nav .= "<li><a href=\"{$file}\">Chương {$no}. ".$e($title).'</a></li>';
            $ncx .= "<navPoint id=\"n{$no}\" playOrder=\"{$no}\"><navLabel><text>Chương {$no}. ".$e($title)."</text></navLabel><content src=\"{$file}\"/></navPoint>";
        }

        $zip->addFromString('OEBPS/nav.xhtml', '<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE html><html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" xml:lang="vi"><head><meta charset="utf-8"/><title>Mục lục</title></head><body><nav epub:type="toc"><h1>Mục lục</h1><ol>'.$nav.'</ol></nav></body></html>');
        $zip->addFromString('OEBPS/toc.ncx', '<?xml version="1.0" encoding="UTF-8"?><ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1"><head><meta name="dtb:uid" content="'.$id.'"/></head><docTitle><text>'.$e($book['title']).'</text></docTitle><navMap>'.$ncx.'</navMap></ncx>');
        $zip->addFromString('OEBPS/content.opf', '<?xml version="1.0" encoding="UTF-8"?><package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="bookid" xml:lang="vi"><metadata xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:identifier id="bookid">'.$id.'</dc:identifier><dc:title>'.$e($book['title']).'</dc:title><dc:creator>'.$e($book['author']).'</dc:creator><dc:language>vi</dc:language><meta property="dcterms:modified">'.now()->utc()->format('Y-m-d\TH:i:s\Z').'</meta></metadata><manifest><item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/><item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/><item id="css" href="style.css" media-type="text/css"/>'.$manifest.'</manifest><spine toc="ncx">'.$spine.'</spine></package>');
        $zip->close();

        DocumentStorage::disk()->put($path, file_get_contents($tmp));
        $size = filesize($tmp);
        File::delete($tmp);

        return $size;
    }

    /** Nội dung mẫu cho một chương: vài đoạn văn + trích dẫn + danh sách ý chính. */
    private function chapterBody(string $book, string $chapter, int $no, int $total): string
    {
        $e = fn (string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $c = mb_strtolower($chapter);
        $paragraphs = [
            "Trong chương này, chúng ta cùng tìm hiểu về {$c} — một phần quan trọng của cuốn \"{$book}\". Nhiều người bỏ qua bước này vì nghĩ nó hiển nhiên, nhưng chính những điều tưởng như đơn giản lại tạo ra khác biệt lớn nhất về lâu dài.",
            'Hãy bắt đầu bằng một câu hỏi: lần gần nhất bạn gặp khó khăn ở việc này là khi nào? Ghi lại tình huống đó thật cụ thể — bạn đang ở đâu, nói chuyện với ai, kết quả ra sao. Có một ví dụ thật trong đầu sẽ giúp bạn hiểu các ý tiếp theo nhanh hơn rất nhiều.',
            'Điều cốt lõi là thay đổi từng chút một. Bạn không cần làm đúng ngay từ đầu; chỉ cần mỗi ngày tốt hơn hôm qua một chút. Sau vài tuần, những thay đổi nhỏ ấy cộng dồn lại thành thói quen, và thói quen sẽ làm thay bạn phần việc khó nhất.',
            'Khi áp dụng, bạn có thể gặp những phản ứng không như mong đợi — từ người khác, hoặc từ chính bản thân mình. Đó là điều bình thường. Hãy coi mỗi lần như vậy là một dữ kiện để điều chỉnh, thay vì bằng chứng rằng cách làm này không hiệu quả.',
        ];
        $html = '';
        foreach ($paragraphs as $p) {
            $html .= '<p>'.$e($p).'</p>';
        }
        $html .= '<blockquote>'.$e('Không cần hoàn hảo, chỉ cần bắt đầu và đều đặn.').'</blockquote>';
        $html .= '<p><strong>Ý chính của chương '.$no.':</strong></p><ul>'
            .'<li>'.$e("Hiểu rõ {$c} giúp bạn chủ động hơn").'</li>'
            .'<li>'.$e('Bắt đầu từ một tình huống thật của chính bạn').'</li>'
            .'<li>'.$e('Thay đổi nhỏ, đều đặn hiệu quả hơn thay đổi lớn một lần').'</li></ul>';
        $html .= '<p><em>'.$e($no < $total ? 'Bài tập: thử áp dụng một ý trong chương này trước khi đọc chương tiếp theo.' : 'Chúc mừng bạn đã đọc hết sách! Hãy chọn một chương để đọc lại sau một tuần thực hành.').'</em></p>';

        return $html;
    }
}
