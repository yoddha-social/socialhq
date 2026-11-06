<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Sources;

use App\SocialHq\Knowledge\Documents\DocumentFailure;
use App\SocialHq\Knowledge\Documents\DocumentSource;
use App\SocialHq\Knowledge\Documents\DocumentUnreadable;

/**
 * Reads a link-shared Google file through its export URL, without a Google
 * sign-in. A file that isn't shared with "Anyone with the link" makes
 * Google answer with a sign-in redirect or page (or 401/403): that is
 * DocumentFailure::NotShared.
 */
final class GoogleExport
{
    /**
     * @return array{text: string, name: string|null}
     *
     * @throws DocumentUnreadable
     */
    public static function read(GoogleLink $link): array
    {
        try {
            $fetched = app(SourceFetcher::class)->fetch(
                $link->exportUrl(),
                self::maxBytes(),
                GoogleLink::allowsHost(...),
                timeoutSeconds: (int) config('socialhq.knowledge.google.timeout_seconds', 20),
            );
        } catch (SourceUnavailable $unavailable) {
            throw match ($unavailable->reason) {
                // Google sends a file it may not share to its sign-in page.
                SourceUnavailable::REDIRECT_ELSEWHERE => DocumentFailure::NotShared->exception(),
                SourceUnavailable::TOO_BIG => DocumentFailure::TooBig->exception(),
                default => DocumentFailure::LinkUnreachable->exception(),
            };
        }

        $status = $fetched->status();

        if (in_array($status, [401, 403], true)) {
            throw DocumentFailure::NotShared->exception();
        }

        if (in_array($status, [404, 410], true)) {
            throw DocumentFailure::NotFound->exception();
        }

        if ($status < 200 || $status >= 300) {
            throw DocumentFailure::LinkUnreachable->exception();
        }

        // An export is never a web page: a page here is Google's sign-in.
        if ($fetched->isHtml()) {
            throw DocumentFailure::NotShared->exception();
        }

        $text = $link->source === DocumentSource::GoogleSheet ? self::sheet($fetched->body()) : $fetched->body();

        return ['text' => $text, 'name' => self::fileName($fetched->header('Content-Disposition'))];
    }

    public static function maxBytes(): int
    {
        return max(1, (int) config('socialhq.knowledge.documents.max_kilobytes', 10240)) * 1024;
    }

    /**
     * @throws DocumentUnreadable
     */
    private static function sheet(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'shq-sheet-');

        try {
            file_put_contents($path, $bytes);

            return SheetText::fromXlsx($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * The file's name without its extension, from Content-Disposition
     * (filename*=UTF-8''… first, then filename="…"), or null.
     */
    private static function fileName(string $disposition): ?string
    {
        $name = null;

        if (preg_match("/filename\\*\\s*=\\s*UTF-8''([^;]+)/i", $disposition, $match) === 1) {
            $name = rawurldecode(trim($match[1], " \"'"));
        } elseif (preg_match('/filename\s*=\s*"([^"]+)"/i', $disposition, $match) === 1) {
            $name = $match[1];
        }

        $name = trim((string) preg_replace('/\p{Cc}/u', '', pathinfo(basename(str_replace('\\', '/', (string) $name)), PATHINFO_FILENAME)));

        return $name === '' ? null : mb_substr($name, 0, 120);
    }
}
