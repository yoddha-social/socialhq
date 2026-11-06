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
 * A file lives until its import is confirmed or dropped; stored names are
 * random, with the extension of the type found in the content.
 */
final class CalendarStorage
{
    public const string DISK = 'local';

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
