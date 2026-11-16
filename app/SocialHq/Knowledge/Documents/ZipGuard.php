<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Documents;

use ZipArchive;

/**
 * Checks an Office file (a zip: Word, PowerPoint, Excel) before anything
 * reads it, from the sizes in the zip's directory: no encrypted part, not
 * too many parts, not more in all than we read, not expanding far more
 * than such files do (a zip bomb), and, when asked, no part over the part
 * limit (an XLSX's shared strings are read whole into memory).
 *
 * Every read of a part is capped as well, whatever its header claims.
 */
final class ZipGuard
{
    public const int MAX_ENTRIES = 10_000;

    /** Expanded bytes per compressed byte. Office XML rarely passes 30. */
    public const int MAX_RATIO = 100;

    /** Below this many expanded bytes the ratio doesn't matter. */
    private const int RATIO_FROM = 10 * 1024 * 1024;

    /**
     * The zip, open, once its directory passes the checks.
     *
     * @throws DocumentUnreadable
     */
    public static function open(string $path, bool $capEachPart = false): ZipArchive
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw DocumentFailure::Unreadable->exception();
        }

        try {
            self::check($zip, $capEachPart);
        } catch (DocumentUnreadable $refused) {
            $zip->close();

            throw $refused;
        }

        return $zip;
    }

    /**
     * Checks the zip and closes it again, for readers that open it themselves.
     *
     * @throws DocumentUnreadable
     */
    public static function assertSafe(string $path, bool $capEachPart = false): void
    {
        self::open($path, $capEachPart)->close();
    }

    public static function maxExpandedBytes(): int
    {
        return (int) config('socialhq.knowledge.documents.max_expanded_bytes', 100 * 1024 * 1024);
    }

    public static function maxPartBytes(): int
    {
        return (int) config('socialhq.knowledge.documents.max_part_bytes', 20 * 1024 * 1024);
    }

    /**
     * @throws DocumentUnreadable
     */
    private static function check(ZipArchive $zip, bool $capEachPart): void
    {
        if ($zip->numFiles > self::MAX_ENTRIES) {
            throw DocumentFailure::TooBig->exception();
        }

        $expanded = 0;
        $compressed = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);

            if ($stat === false) {
                continue;
            }

            if (($stat['encryption_method'] ?? 0) !== 0) {
                throw DocumentFailure::Protected->exception();
            }

            $size = (int) $stat['size'];

            if ($capEachPart && $size > self::maxPartBytes()) {
                throw DocumentFailure::TooBig->exception();
            }

            $expanded += $size;
            $compressed += (int) $stat['comp_size'];
        }

        if ($expanded > self::maxExpandedBytes()) {
            throw DocumentFailure::TooBig->exception();
        }

        if ($expanded > self::RATIO_FROM && $expanded > max(1, $compressed) * self::MAX_RATIO) {
            throw DocumentFailure::TooBig->exception();
        }
    }
}
