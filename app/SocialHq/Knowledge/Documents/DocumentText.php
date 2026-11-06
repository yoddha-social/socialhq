<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Documents;

use DOMDocument;
use DOMElement;
use DOMNode;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
use Throwable;
use ZipArchive;

/**
 * Reads the text out of a knowledge document: PDF (smalot/pdfparser, without
 * image content and with a decode memory limit), Word (word/document.xml,
 * tables as "cell | cell" lines), PowerPoint (each slide in the deck's
 * order) and plain text or Markdown (converted to UTF-8).
 *
 * Office files are zips: their sizes are checked before anything is read,
 * and every read is capped, so a zip bomb fails as TooBig instead of filling
 * memory. The text is cleaned (control characters out, whitespace
 * normalised, paragraphs kept) and cut at the configured length.
 *
 * @throws DocumentUnreadable
 */
final class DocumentText
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const A = 'http://schemas.openxmlformats.org/drawingml/2006/main';

    private const P = 'http://schemas.openxmlformats.org/presentationml/2006/main';

    private const R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const REL = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /** Fewer letters and digits than this is no text (a scan's stray marks). */
    private const MIN_LETTERS = 20;

    public static function extract(string $path, DocumentType $type): ExtractedText
    {
        [$raw, $pages] = match ($type) {
            DocumentType::Pdf => self::pdf($path),
            DocumentType::Docx => [self::docx($path), null],
            DocumentType::Pptx => self::pptx($path),
            DocumentType::Text, DocumentType::Markdown => [self::plain($path), null],
        };

        $text = self::clean($raw);

        if (preg_match_all('/[\p{L}\p{N}]/u', $text) < self::MIN_LETTERS) {
            throw DocumentFailure::NoText->exception();
        }

        [$text, $truncated] = self::cut($text, self::maxCharacters());

        return new ExtractedText($text, $pages, $truncated);
    }

    public static function maxCharacters(): int
    {
        return max(1000, (int) config('socialhq.knowledge.documents.max_characters', 300000));
    }

    /**
     * Control characters out (newlines and tabs kept), runs of spaces as one,
     * lines trimmed, at most one blank line between paragraphs.
     */
    public static function clean(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');
        $text = str_replace(["\r\n", "\r", "\u{2028}", "\u{2029}"], "\n", $text);
        // Control characters, private-use glyphs (icon fonts in PDFs) and soft hyphens.
        $text = (string) preg_replace('/(?![\n\t])\p{Cc}|\p{Co}|\x{00AD}|\x{FEFF}/u', '', $text);
        $text = (string) preg_replace('/\h+/u', ' ', $text);
        $text = (string) preg_replace('/ ?\n ?/', "\n", $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * At most $max characters, cut at a space when there is one nearby.
     *
     * @return array{0: string, 1: bool}
     */
    private static function cut(string $text, int $max): array
    {
        if (mb_strlen($text) <= $max) {
            return [$text, false];
        }

        $kept = mb_substr($text, 0, $max);
        $space = mb_strrpos($kept, ' ');

        if ($space !== false && $space > $max - 200) {
            $kept = mb_substr($kept, 0, $space);
        }

        return [rtrim($kept), true];
    }

    /**
     * @return array{0: string, 1: int}
     */
    private static function pdf(string $path): array
    {
        $config = new Config;
        $config->setRetainImageContent(false);
        $config->setDecodeMemoryLimit((int) config('socialhq.knowledge.documents.pdf_decode_bytes', 32 * 1024 * 1024));

        try {
            $document = (new Parser([], $config))->parseFile($path);
            $pages = $document->getPages();
            $texts = [];

            foreach ($pages as $page) {
                $texts[] = $page->getText();
            }
        } catch (Throwable $exception) {
            throw str_contains(strtolower($exception->getMessage()), 'secured')
                ? DocumentFailure::Protected->exception()
                : DocumentFailure::Unreadable->exception();
        }

        return [implode("\n\n", $texts), count($pages)];
    }

    private static function docx(string $path): string
    {
        $zip = self::openZip($path);

        try {
            $body = self::xml(self::read($zip, 'word/document.xml'))->getElementsByTagNameNS(self::W, 'body')->item(0);
        } finally {
            $zip->close();
        }

        return $body instanceof DOMElement ? implode("\n\n", self::wordBlocks($body)) : '';
    }

    /**
     * Paragraphs and table rows of a Word body, in order.
     *
     * @return list<string>
     */
    private static function wordBlocks(DOMNode $node): array
    {
        $blocks = [];

        foreach ($node->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            if ($child->namespaceURI === self::W && $child->localName === 'p') {
                $blocks[] = trim(self::wordRun($child));
            } elseif ($child->namespaceURI === self::W && $child->localName === 'tbl') {
                foreach ($child->childNodes as $row) {
                    if ($row instanceof DOMElement && $row->localName === 'tr') {
                        $cells = [];

                        foreach ($row->childNodes as $cell) {
                            if ($cell instanceof DOMElement && $cell->localName === 'tc') {
                                $cells[] = implode(' ', self::wordBlocks($cell));
                            }
                        }

                        $blocks[] = implode(' | ', array_filter(array_map(trim(...), $cells), fn (string $cell): bool => $cell !== ''));
                    }
                }
            } elseif (! in_array($child->localName, ['pPr', 'rPr', 'tblPr', 'sectPr'], true)) {
                // Content controls, tracked insertions and the like hold paragraphs too.
                array_push($blocks, ...self::wordBlocks($child));
            }
        }

        return array_values(array_filter($blocks, fn (string $block): bool => $block !== ''));
    }

    /**
     * The text of one Word paragraph: its runs, tabs and line breaks.
     */
    private static function wordRun(DOMNode $node): string
    {
        $text = '';

        foreach ($node->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $text .= match ($child->localName) {
                't' => $child->textContent,
                'tab' => "\t",
                'br', 'cr' => "\n",
                // Deleted text, field codes and formatting are not content.
                'delText', 'instrText', 'rPr', 'pPr' => '',
                'p' => "\n".self::wordRun($child),
                default => self::wordRun($child),
            };
        }

        return $text;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private static function pptx(string $path): array
    {
        $zip = self::openZip($path);

        try {
            $slides = [];

            foreach (self::slideOrder($zip) as $name) {
                $paragraphs = [];

                foreach (self::xml(self::read($zip, $name))->getElementsByTagNameNS(self::A, 'p') as $paragraph) {
                    $line = '';

                    foreach ($paragraph->getElementsByTagNameNS(self::A, '*') as $part) {
                        $line .= match ($part->localName) {
                            't' => $part->textContent,
                            'br' => "\n",
                            default => '',
                        };
                    }

                    $paragraphs[] = trim($line);
                }

                $slides[] = implode("\n", array_filter($paragraphs, fn (string $line): bool => $line !== ''));
            }
        } finally {
            $zip->close();
        }

        return [implode("\n\n", $slides), count($slides)];
    }

    /**
     * The slide parts in the order the deck shows them (presentation.xml),
     * or by their number when that can't be read.
     *
     * @return list<string>
     */
    private static function slideOrder(ZipArchive $zip): array
    {
        $byNumber = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if (preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $match) === 1) {
                $byNumber[(int) $match[1]] = $name;
            }
        }

        ksort($byNumber);
        $byNumber = array_values($byNumber);

        try {
            $targets = [];

            foreach (self::xml(self::read($zip, 'ppt/_rels/presentation.xml.rels'))->getElementsByTagNameNS(self::REL, 'Relationship') as $relationship) {
                $target = ltrim($relationship->getAttribute('Target'), '/');
                $targets[$relationship->getAttribute('Id')] = str_starts_with($target, 'ppt/') ? $target : "ppt/{$target}";
            }

            $ordered = [];

            foreach (self::xml(self::read($zip, 'ppt/presentation.xml'))->getElementsByTagNameNS(self::P, 'sldId') as $slide) {
                $name = $targets[$slide->getAttributeNS(self::R, 'id')] ?? null;

                if ($name !== null && in_array($name, $byNumber, true)) {
                    $ordered[] = $name;
                }
            }
        } catch (DocumentUnreadable) {
            return $byNumber;
        }

        return $ordered === [] ? $byNumber : $ordered;
    }

    private static function plain(string $path): string
    {
        $raw = (string) file_get_contents($path);

        return match (true) {
            str_starts_with($raw, "\xEF\xBB\xBF") => substr($raw, 3),
            str_starts_with($raw, "\xFF\xFE") => (string) mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE'),
            str_starts_with($raw, "\xFE\xFF") => (string) mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE'),
            mb_check_encoding($raw, 'UTF-8') => $raw,
            // Not UTF-8: most likely a Windows export (a superset of Latin-1).
            default => (string) mb_convert_encoding($raw, 'UTF-8', 'Windows-1252'),
        };
    }

    /**
     * Opens an Office file, refusing one whose parts add up to more than we
     * read (sizes from the zip's directory; each read is capped as well).
     */
    private static function openZip(string $path): ZipArchive
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw DocumentFailure::Unreadable->exception();
        }

        $total = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);

            if ($stat === false) {
                continue;
            }

            if (($stat['encryption_method'] ?? 0) !== 0) {
                $zip->close();

                throw DocumentFailure::Protected->exception();
            }

            $total += (int) $stat['size'];
        }

        if ($zip->numFiles > 10000 || $total > self::maxExpandedBytes()) {
            $zip->close();

            throw DocumentFailure::TooBig->exception();
        }

        return $zip;
    }

    /**
     * One part of a zip, at most the part limit, whatever its header claims.
     */
    private static function read(ZipArchive $zip, string $name): string
    {
        $limit = self::maxPartBytes();
        $stat = $zip->statName($name);

        if ($stat === false) {
            throw DocumentFailure::Unreadable->exception();
        }

        if ((int) $stat['size'] > $limit) {
            throw DocumentFailure::TooBig->exception();
        }

        $content = $zip->getFromName($name, $limit + 1);

        if ($content === false) {
            throw DocumentFailure::Unreadable->exception();
        }

        if (strlen($content) > $limit) {
            throw DocumentFailure::TooBig->exception();
        }

        return $content;
    }

    /**
     * Parses one XML part without network access, refusing any DTD (Office
     * parts never have one; it is how entity bombs get in).
     */
    private static function xml(string $content): DOMDocument
    {
        if (stripos($content, '<!DOCTYPE') !== false) {
            throw DocumentFailure::Unreadable->exception();
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            if (! $document->loadXML($content, LIBXML_NONET | LIBXML_COMPACT)) {
                throw DocumentFailure::Unreadable->exception();
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $document;
    }

    private static function maxExpandedBytes(): int
    {
        return (int) config('socialhq.knowledge.documents.max_expanded_bytes', 100 * 1024 * 1024);
    }

    private static function maxPartBytes(): int
    {
        return (int) config('socialhq.knowledge.documents.max_part_bytes', 20 * 1024 * 1024);
    }
}
