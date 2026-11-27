<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Documents;

/**
 * What the Preview dialog on Brand knowledge shows, always read from the
 * app's own copy, never fetched from anywhere:
 *
 * - a PDF: the file itself, in the browser's own viewer, framed from the
 *   app's origin (`socialhq.brand.documents.view`, PDFs only);
 * - a text file (an upload, a Google file, the website's text): its text;
 * - a Markdown file: rendered by SafeMarkdown (no HTML, pictures or
 *   scripts from the file);
 * - a Word or PowerPoint file: the text DocumentText reads from it, as the
 *   AI gets it (no layout or pictures);
 * - the pages a "Website text" suggestion would keep (pages()).
 *
 * Text is cut to the first `preview_kilobytes` (100 KB) of UTF-8.
 */
final class DocumentPreview
{
    public const string PDF = 'pdf';

    public const string TEXT = 'text';

    public const string MARKDOWN = 'markdown';

    /** Text read out of a Word or PowerPoint file. */
    public const string EXTRACTED = 'extracted';

    /** The pages of a "Website text" suggestion. */
    public const string PAGES = 'pages';

    /** Nothing to show: the file is gone or can't be read. */
    public const string NONE = 'none';

    /**
     * @return array<string, mixed>
     */
    public static function of(KnowledgeDocument $document): array
    {
        $type = $document->type();
        $disk = DocumentStorage::disk();

        if ($type === null || blank($document->path) || ! $disk->exists($document->path)) {
            return self::answer(self::NONE);
        }

        if ($type === DocumentType::Pdf) {
            return self::answer(self::PDF, fileUrl: route('socialhq.brand.documents.view', $document->id));
        }

        if ($type === DocumentType::Text || $type === DocumentType::Markdown) {
            [$text, $truncated] = self::fileText($document->path);

            return $type === DocumentType::Markdown
                ? self::answer(self::MARKDOWN, html: SafeMarkdown::toHtml($text), truncated: $truncated)
                : self::answer(self::TEXT, text: $text, truncated: $truncated);
        }

        try {
            $extracted = DocumentText::extract($disk->path($document->path), $type);
        } catch (DocumentUnreadable) {
            return self::answer(self::NONE);
        }

        [$text, $truncated] = self::cut($extracted->text);

        return self::answer(self::EXTRACTED, text: $text, truncated: $truncated || $extracted->truncated);
    }

    /**
     * The pages a "Website text" suggestion keeps, in order, until the
     * preview's size is used up (the last one shown may be cut).
     *
     * @param  list<array{url: string, title: string, text: string}>  $pages
     * @return array<string, mixed>
     */
    public static function pages(array $pages): array
    {
        $room = self::maxBytes();
        $shown = [];
        $truncated = false;

        foreach ($pages as $page) {
            if ($room <= 0) {
                $truncated = true;

                break;
            }

            [$text, $cut] = self::cut($page['text'], $room);
            $shown[] = ['url' => $page['url'], 'title' => $page['title'], 'text' => $text];
            $room -= strlen($text);

            if ($cut) {
                $truncated = true;

                break;
            }
        }

        return [...self::answer(self::PAGES, truncated: $truncated), 'pages' => $shown, 'count' => count($pages)];
    }

    public static function maxBytes(): int
    {
        return max(1, (int) config('socialhq.knowledge.documents.preview_kilobytes', 100)) * 1024;
    }

    /**
     * @return array<string, mixed>
     */
    private static function answer(string $kind, ?string $text = null, ?string $html = null, bool $truncated = false, ?string $fileUrl = null): array
    {
        return [
            'kind' => $kind,
            'text' => $text,
            'html' => $html,
            'pages' => [],
            'count' => 0,
            'truncated' => $truncated,
            'kilobytes' => intdiv(self::maxBytes(), 1024),
            'file_url' => $fileUrl,
        ];
    }

    /**
     * The start of a stored text file as UTF-8, and whether there is more.
     * Only the first maxBytes() are read.
     *
     * @return array{0: string, 1: bool}
     */
    private static function fileText(string $path): array
    {
        $max = self::maxBytes();
        $stream = DocumentStorage::disk()->readStream($path);

        if (! is_resource($stream)) {
            return ['', false];
        }

        try {
            $raw = (string) stream_get_contents($stream, $max + 1);
        } finally {
            fclose($stream);
        }

        $truncated = strlen($raw) > $max;
        $text = DocumentText::utf8($truncated ? self::wholeCharacters(substr($raw, 0, $max)) : $raw);

        // Line breaks as \n, control characters out (tabs kept).
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return [(string) preg_replace('/(?![\n\t])\p{Cc}/u', '', $text), $truncated];
    }

    /**
     * Bytes cut at the limit without half a character at the end, so the
     * file's encoding is still recognised (UTF-16 by its byte-order mark).
     */
    private static function wholeCharacters(string $bytes): string
    {
        if (str_starts_with($bytes, "\xFF\xFE") || str_starts_with($bytes, "\xFE\xFF")) {
            return substr($bytes, 0, strlen($bytes) - (strlen($bytes) % 2));
        }

        // A UTF-8 character is at most 4 bytes: drop up to 3 of an unfinished one.
        foreach (range(0, 3) as $drop) {
            $kept = substr($bytes, 0, strlen($bytes) - $drop);

            if (mb_check_encoding($kept, 'UTF-8')) {
                return $kept;
            }
        }

        // Not UTF-8 at all: read as Windows-1252, where every byte is a character.
        return $bytes;
    }

    /**
     * UTF-8 text cut to at most $bytes, on a character boundary.
     *
     * @return array{0: string, 1: bool}
     */
    private static function cut(string $text, ?int $bytes = null): array
    {
        $bytes ??= self::maxBytes();

        return strlen($text) > $bytes ? [mb_strcut($text, 0, $bytes, 'UTF-8'), true] : [$text, false];
    }
}
