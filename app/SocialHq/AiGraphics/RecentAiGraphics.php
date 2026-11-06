<?php

declare(strict_types=1);

namespace App\SocialHq\AiGraphics;

use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use App\Support\MediaImportStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A person's recent AI graphics in a workspace, and attaching one again: a
 * graphic finished after its editor closed would otherwise be lost, since
 * TryPost has no media library for loose uploads.
 */
final class RecentAiGraphics
{
    public const DAYS = 7;

    public const LIMIT = 12;

    /**
     * @return Collection<int, AiGraphic>
     */
    public static function for(User $user, Workspace $workspace): Collection
    {
        return AiGraphic::query()
            ->where('user_id', $user->id)
            ->where('workspace_id', $workspace->id)
            ->where('status', AiGraphic::GENERATED)
            ->where('created_at', '>=', now()->subDays(self::DAYS))
            ->latest('id')
            ->limit(self::LIMIT)
            ->get()
            ->filter(fn (AiGraphic $graphic): bool => self::bytes($graphic) !== null)
            ->values();
    }

    /**
     * The graphic's file: our own copy, or the upload it was first stored as.
     */
    public static function bytes(AiGraphic $graphic): ?string
    {
        if ($graphic->result_path !== null && Storage::disk('local')->exists($graphic->result_path)) {
            return Storage::disk('local')->get($graphic->result_path);
        }

        $media = $graphic->media_id !== null ? Media::query()->find($graphic->media_id) : null;

        return $media !== null && Storage::exists($media->path) ? Storage::get($media->path) : null;
    }

    public static function mime(string $bytes): string
    {
        return (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'image/png';
    }

    /**
     * A fresh upload of the graphic, as a finished media import the composer
     * attaches. Returns the import id, or null when the file is gone.
     */
    public static function attachAgain(AiGraphic $graphic, User $user, Workspace $workspace): ?string
    {
        $bytes = self::bytes($graphic);

        if ($bytes === null) {
            return null;
        }

        $mime = self::mime($bytes);
        $path = tempnam(sys_get_temp_dir(), 'shq-ai-graphic-');

        try {
            file_put_contents($path, $bytes);

            $media = $workspace->addMediaFromPath(
                $path,
                "ai-graphic-{$graphic->id}.".AiGraphics::extensionFor($mime),
                Media::COLLECTION_UPLOADS,
                ['source' => 'socialhq_ai', 'ai_graphic_id' => $graphic->id],
                mimeType: $mime,
            );
            $media->issueUploadToken();
        } finally {
            @unlink($path);
        }

        $importId = (string) Str::uuid();
        MediaImportStatus::pending($importId, $user->id, $workspace->id);
        MediaImportStatus::complete($importId, $media);

        return $importId;
    }
}
