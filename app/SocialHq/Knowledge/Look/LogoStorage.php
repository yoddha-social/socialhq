<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Look;

use App\Models\Workspace;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Where brand logos live: the private `local` disk under
 * socialhq/brand/{workspace}/, never the default disk (in production a
 * public bucket). Served only to members, through BrandLookController.
 */
final class LogoStorage
{
    public const string DISK = 'local';

    public static function disk(): FilesystemAdapter
    {
        return Storage::disk(self::DISK);
    }

    public static function directory(string $workspaceId): string
    {
        return "socialhq/brand/{$workspaceId}";
    }

    public static function store(Workspace $workspace, LogoFile $logo): string
    {
        $path = self::directory((string) $workspace->id).'/logo-'.Str::uuid()->toString().".{$logo->extension}";
        self::disk()->put($path, $logo->bytes);

        return $path;
    }

    /**
     * Delete a replaced or removed logo once the change is committed.
     */
    public static function deleteLater(?string $path): void
    {
        if (filled($path)) {
            DB::afterCommit(fn () => self::disk()->delete((string) $path));
        }
    }

    /**
     * The logo goes with its workspace (whose rows cascade in the database, firing no model events).
     */
    public static function registerCleanup(): void
    {
        Workspace::deleting(function (Workspace $workspace): void {
            $directory = self::directory((string) $workspace->id);

            DB::afterCommit(fn () => self::disk()->deleteDirectory($directory));
        });
    }
}
