<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Sources;

use App\SocialHq\Knowledge\Documents\DocumentSource;
use Illuminate\Support\Uri;
use Throwable;

/**
 * A link to a Google Docs, Sheets or Slides file, shared with "Anyone with
 * the link". Only the file's id is taken from what was pasted: the export
 * URL is built from the id on Google's host (config), never from the link.
 */
final readonly class GoogleLink
{
    private const PATHS = [
        'document' => DocumentSource::GoogleDoc,
        'spreadsheets' => DocumentSource::GoogleSheet,
        'presentation' => DocumentSource::GoogleSlides,
    ];

    private function __construct(public DocumentSource $source, public string $id) {}

    public static function parse(string $url): ?self
    {
        $url = trim($url);

        if (strlen($url) > 2000 || preg_match('/[\s\x00-\x1f]/', $url) === 1) {
            return null;
        }

        try {
            $uri = Uri::of($url);
        } catch (Throwable) {
            return null;
        }

        if (strtolower((string) $uri->scheme()) !== 'https' || strtolower((string) $uri->host()) !== self::host() || filled($uri->user()) || $uri->port() !== null) {
            return null;
        }

        if (preg_match('#^/(?:u/\d+/)?(document|spreadsheets|presentation)/d/([A-Za-z0-9_-]{20,128})(?:/|$)#', '/'.$uri->path(), $match) !== 1) {
            return null;
        }

        return new self(self::PATHS[$match[1]], $match[2]);
    }

    public static function host(): string
    {
        return strtolower((string) config('socialhq.knowledge.google.host', 'docs.google.com'));
    }

    /**
     * Where the file's text is read: plain text for Docs and Slides, the
     * whole workbook (every sheet) for Sheets.
     */
    public function exportUrl(): string
    {
        $base = 'https://'.self::host();

        return match ($this->source) {
            DocumentSource::GoogleDoc => "{$base}/document/d/{$this->id}/export?format=txt",
            DocumentSource::GoogleSheet => "{$base}/spreadsheets/d/{$this->id}/export?format=xlsx",
            default => "{$base}/presentation/d/{$this->id}/export/txt",
        };
    }

    /**
     * The link stored and shown ("Open in Google"), rebuilt from the id.
     */
    public function canonicalUrl(): string
    {
        $base = 'https://'.self::host();

        return match ($this->source) {
            DocumentSource::GoogleDoc => "{$base}/document/d/{$this->id}/edit",
            DocumentSource::GoogleSheet => "{$base}/spreadsheets/d/{$this->id}/edit",
            default => "{$base}/presentation/d/{$this->id}/edit",
        };
    }

    /**
     * Google answers an export from its own host or its download host.
     */
    public static function allowsHost(string $host): bool
    {
        return $host === self::host() || str_ends_with($host, '.googleusercontent.com');
    }
}
