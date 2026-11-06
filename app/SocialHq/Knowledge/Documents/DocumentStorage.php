<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Documents;

use App\Models\Workspace;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Where knowledge documents live: the private `local` disk under
 * socialhq/knowledge/{workspace}/, never the default disk (in production a
 * public bucket). Served only through KnowledgeDocumentController. Stored
 * names are random, with the extension of the sniffed type.
 */
final class DocumentStorage
{
    public const string DISK = 'local';

    public static function disk(): FilesystemAdapter
    {
        return Storage::disk(self::DISK);
    }

    public static function directory(string $workspaceId): string
    {
        return "socialhq/knowledge/{$workspaceId}";
    }

    /**
     * Delete files with the rows that own them: the document, or its
     * workspace (whose rows cascade in the database, firing no model events).
     * Files go once the surrounding transaction commits.
     */
    public static function registerCleanup(): void
    {
        KnowledgeDocument::deleted(function (KnowledgeDocument $document): void {
            DB::afterCommit(fn () => self::disk()->delete($document->path));
        });

        Workspace::deleting(function (Workspace $workspace): void {
            $directory = self::directory((string) $workspace->id);

            DB::afterCommit(fn () => self::disk()->deleteDirectory($directory));
        });
    }
}
