<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Calendars;

use App\Models\Workspace;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Where uploaded calendars wait for their column mapping: the private
 * `local` disk, never the default disk (in production a public bucket).
 * What waits is the upload's sheets as read (JSON), not the spreadsheet, so
 * a file is parsed once. It lives until its import is confirmed or dropped;
 * stored names are random.
 */
final class CalendarStorage
{
    public const string DISK = 'local';

    /** The extension of an upload's sheets as read (putSheets). */
    private const string SHEETS = 'json';

    public static function disk(): FilesystemAdapter
    {
        return Storage::disk(self::DISK);
    }

    public static function directory(Workspace|string $workspace): string
    {
        $id = $workspace instanceof Workspace ? $workspace->id : $workspace;

        return "socialhq/calendars/{$id}";
    }

    public static function newPath(Workspace $workspace, string $type): string
    {
        return self::directory($workspace).'/'.Str::uuid7()->toString().".{$type}";
    }

    /**
     * Keeps an upload's sheets as read, so the import step never reads the
     * spreadsheet again. Returns the stored path.
     *
     * @param  list<CalendarSheet>  $sheets
     */
    public static function putSheets(Workspace $workspace, array $sheets): string
    {
        $path = self::newPath($workspace, self::SHEETS);
        $json = json_encode(array_map(fn (CalendarSheet $sheet): array => $sheet->toArray(), $sheets), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);

        self::disk()->put($path, $json);

        return $path;
    }

    /**
     * The sheets an upload kept, or null when the file isn't one putSheets()
     * wrote (an upload from before the sheets were kept: read it again).
     *
     * @return list<CalendarSheet>|null
     *
     * @throws CalendarFileProblem
     */
    public static function sheets(string $path): ?array
    {
        if (! str_ends_with($path, '.'.self::SHEETS)) {
            return null;
        }

        $stored = json_decode((string) self::disk()->get($path), true);

        if (! is_array($stored)) {
            throw new CalendarFileProblem('unreadable');
        }

        return array_map(fn (mixed $sheet): CalendarSheet => CalendarSheet::fromArray((array) $sheet), array_values($stored));
    }

    /**
     * Delete a file once the surrounding transaction commits.
     */
    public static function forget(?string $path): void
    {
        if (blank($path)) {
            return;
        }

        DB::afterCommit(fn () => self::disk()->delete($path));
    }

    /**
     * Files go with their import, and with a deleted brand (its rows cascade
     * in the database, which fires no model events).
     */
    public static function registerCleanup(): void
    {
        CalendarImport::deleting(fn (CalendarImport $import) => self::forget($import->path));
        Workspace::deleting(fn (Workspace $workspace) => DB::afterCommit(fn () => self::disk()->deleteDirectory(self::directory($workspace))));
    }
}
