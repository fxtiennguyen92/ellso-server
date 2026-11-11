<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * Cắt EPUB thành bản đọc thử: giữ N chương đầu (N mục "linear" đầu tiên trong spine của OPF).
 *
 * - Nội dung các chương sau bị xoá khỏi manifest, spine VÀ khỏi file zip (không tải được phần còn lại).
 * - Giữ CSS / font / ảnh, file mục lục (nav EPUB 3, toc.ncx EPUB 2); mục lục được bỏ các mục trỏ tới chương đã xoá.
 * - `mimetype` luôn là file đầu tiên, không nén (yêu cầu của chuẩn EPUB).
 */
class EpubPreview
{
    private const OPF_NS = 'http://www.idpf.org/2007/opf';

    private const NCX_NS = 'http://www.daisy.org/z3986/2005/ncx/';

    private const XHTML_NS = 'http://www.w3.org/1999/xhtml';

    /** Số chương (mục linear trong spine) của sách. */
    public static function chapterCount(string $source): int
    {
        $zip = self::open($source);
        try {
            [, $opf] = self::loadOpf($zip);

            return count(self::linearItemrefs($opf));
        } finally {
            $zip->close();
        }
    }

    /**
     * Tạo bản đọc thử tại $target.
     *
     * @return array{kept: int, total: int} số chương giữ lại / tổng số chương
     */
    public static function make(string $source, string $target, int $chapters): array
    {
        $zip = self::open($source);

        try {
            [$opfPath, $opf] = self::loadOpf($zip);
            $opfDir = self::dir($opfPath);
            $xp = self::xpath($opf);

            $manifest = []; // id => [href (đường dẫn trong zip), element]
            foreach ($xp->query('//opf:manifest/opf:item') as $item) {
                /** @var DOMElement $item */
                $manifest[$item->getAttribute('id')] = [self::resolve($opfDir, $item->getAttribute('href')), $item];
            }

            $linear = self::linearItemrefs($opf);
            $total = count($linear);
            if ($total === 0) {
                throw new RuntimeException('EPUB không có chương nào trong spine.');
            }
            $keep = BookPreview::limit($chapters, $total);
            $cutAfter = $linear[$keep - 1];

            // File không được xoá dù nằm trong spine: mục lục (nav)
            $protected = [];
            foreach ($manifest as $id => [$href, $item]) {
                if (in_array('nav', preg_split('/\s+/', (string) $item->getAttribute('properties')), true)) {
                    $protected[$id] = true;
                }
            }

            // Bỏ mọi itemref sau chương thứ N (cả mục non-linear phía sau)
            $removedIds = [];
            $after = false;
            foreach (iterator_to_array($xp->query('//opf:spine/opf:itemref')) as $ref) {
                /** @var DOMElement $ref */
                if ($after) {
                    $idref = $ref->getAttribute('idref');
                    $ref->parentNode->removeChild($ref);
                    if (! isset($protected[$idref])) {
                        $removedIds[$idref] = true;
                    }
                }
                if ($ref === $cutAfter) {
                    $after = true;
                }
            }

            // Một file có thể được tham chiếu bởi chương còn giữ (hiếm) → chỉ xoá file không còn itemref nào dùng
            $keptIds = [];
            foreach ($xp->query('//opf:spine/opf:itemref') as $ref) {
                $keptIds[$ref->getAttribute('idref')] = true;
            }
            $removedFiles = [];
            foreach (array_keys($removedIds) as $id) {
                if (isset($keptIds[$id]) || ! isset($manifest[$id])) {
                    continue;
                }
                [$href, $item] = $manifest[$id];
                $item->parentNode->removeChild($item);
                $removedFiles[$href] = true;
            }

            // <guide> (EPUB 2) trỏ tới chương đã xoá
            foreach (iterator_to_array($xp->query('//opf:guide/opf:reference')) as $reference) {
                /** @var DOMElement $reference */
                if (isset($removedFiles[self::resolve($opfDir, $reference->getAttribute('href'))])) {
                    $reference->parentNode->removeChild($reference);
                }
            }

            $replacements = [$opfPath => $opf->saveXML()];

            // Mục lục EPUB 3 (nav) + EPUB 2 (toc.ncx)
            foreach ($manifest as $id => [$href, $item]) {
                if ($item->parentNode === null) {
                    continue; // đã bị xoá
                }
                $props = preg_split('/\s+/', (string) $item->getAttribute('properties'));
                $type = $item->getAttribute('media-type');
                $content = $zip->getFromName($href);
                if ($content === false) {
                    continue;
                }
                if (in_array('nav', $props, true)) {
                    $replacements[$href] = self::pruneNav($content, self::dir($href), $removedFiles) ?? $content;
                } elseif ($type === 'application/x-dtbncx+xml') {
                    $replacements[$href] = self::pruneNcx($content, self::dir($href), $removedFiles) ?? $content;
                }
            }

            self::write($zip, $target, $replacements, $removedFiles);

            return ['kept' => $keep, 'total' => $total];
        } finally {
            $zip->close();
        }
    }

    private static function open(string $path): ZipArchive
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Không mở được file EPUB.');
        }

        return $zip;
    }

    /** @return array{0: string, 1: DOMDocument} đường dẫn OPF trong zip + nội dung */
    private static function loadOpf(ZipArchive $zip): array
    {
        $container = $zip->getFromName('META-INF/container.xml');
        if ($container === false) {
            throw new RuntimeException('EPUB thiếu META-INF/container.xml.');
        }
        $doc = self::xml($container);
        $rootfile = null;
        foreach ($doc->getElementsByTagName('rootfile') as $node) {
            $rootfile = $node->getAttribute('full-path');
            break;
        }
        if (! $rootfile) {
            throw new RuntimeException('EPUB không khai báo file OPF.');
        }
        $opf = $zip->getFromName($rootfile);
        if ($opf === false) {
            throw new RuntimeException('Không tìm thấy file OPF: ' . $rootfile);
        }

        return [$rootfile, self::xml($opf)];
    }

    /** @return list<DOMElement> itemref "linear" (linear khác "no") theo thứ tự đọc */
    private static function linearItemrefs(DOMDocument $opf): array
    {
        $refs = [];
        foreach (self::xpath($opf)->query('//opf:spine/opf:itemref') as $ref) {
            /** @var DOMElement $ref */
            if (strtolower($ref->getAttribute('linear')) !== 'no') {
                $refs[] = $ref;
            }
        }

        return $refs;
    }

    /** Bỏ các <li> của nav trỏ tới chương đã xoá. */
    private static function pruneNav(string $content, string $base, array $removed): ?string
    {
        $doc = self::xml($content, quiet: true);
        if (! $doc) {
            return null;
        }
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('h', self::XHTML_NS);
        $links = $xp->query('//h:nav//h:a[@href]');
        if ($links->length === 0) {
            $links = $xp->query('//nav//a[@href]'); // nav không khai báo namespace
        }
        foreach (iterator_to_array($links) as $a) {
            /** @var DOMElement $a */
            if (! isset($removed[self::resolve($base, $a->getAttribute('href'))])) {
                continue;
            }
            $li = $a->parentNode;
            while ($li && $li->nodeName !== 'li' && $li->localName !== 'li') {
                $li = $li->parentNode;
            }
            $li?->parentNode?->removeChild($li);
        }

        return $doc->saveXML();
    }

    /** Bỏ các navPoint của toc.ncx trỏ tới chương đã xoá. */
    private static function pruneNcx(string $content, string $base, array $removed): ?string
    {
        $doc = self::xml($content, quiet: true);
        if (! $doc) {
            return null;
        }
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('n', self::NCX_NS);
        foreach (iterator_to_array($xp->query('//n:navPoint')) as $point) {
            /** @var DOMElement $point */
            $src = $xp->query('n:content', $point)->item(0);
            if ($src instanceof DOMElement && isset($removed[self::resolve($base, $src->getAttribute('src'))]) && $point->parentNode) {
                $point->parentNode->removeChild($point);
            }
        }

        return $doc->saveXML();
    }

    /** Ghi zip mới: mimetype đầu tiên (không nén), bỏ file đã xoá, thay file đã sửa. */
    private static function write(ZipArchive $source, string $target, array $replacements, array $removed): void
    {
        @unlink($target);
        $out = new ZipArchive;
        if ($out->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Không tạo được file EPUB đọc thử.');
        }

        $out->addFromString('mimetype', 'application/epub+zip');
        $out->setCompressionName('mimetype', ZipArchive::CM_STORE);

        for ($i = 0; $i < $source->numFiles; $i++) {
            $name = $source->getNameIndex($i);
            if ($name === 'mimetype' || str_ends_with($name, '/') || isset($removed[$name])) {
                continue;
            }
            $out->addFromString($name, $replacements[$name] ?? $source->getFromIndex($i));
        }

        if (! $out->close()) {
            throw new RuntimeException('Không ghi được file EPUB đọc thử.');
        }
    }

    private static function xml(string $content, bool $quiet = false): ?DOMDocument
    {
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($content, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $ok) {
            if ($quiet) {
                return null;
            }
            throw new RuntimeException('EPUB có file XML không hợp lệ.');
        }
        // Không khai báo encoding → DOM ghi ra ASCII + mã thực thể; giữ UTF-8 cho dễ đọc
        $doc->encoding ??= 'UTF-8';
        if ($doc->encoding === '') {
            $doc->encoding = 'UTF-8';
        }

        return $doc;
    }

    private static function xpath(DOMDocument $doc): DOMXPath
    {
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('opf', self::OPF_NS);

        return $xp;
    }

    private static function dir(string $path): string
    {
        $dir = dirname($path);

        return $dir === '.' ? '' : $dir . '/';
    }

    /** Đường dẫn trong zip của một href tương đối (bỏ #fragment, giải mã %20, xử lý ../). */
    private static function resolve(string $base, string $href): string
    {
        $href = rawurldecode(explode('#', $href, 2)[0]);
        $parts = [];
        foreach (explode('/', $base . $href) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
            } else {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }
}
