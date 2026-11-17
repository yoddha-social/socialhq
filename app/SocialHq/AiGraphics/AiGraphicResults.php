<?php

declare(strict_types=1);

namespace App\SocialHq\AiGraphics;

use App\Models\Workspace;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Our own copy of each generated graphic (so "Recent AI graphics" can attach
 * it again after its upload is gone): the private `local` disk under
 * socialhq/ai-graphics/results/{workspace}/. Graphics generated before that
 * layout keep their stored result_path (socialhq/ai-graphics/results/{id}.ext)
 * and are read and deleted the same way. Copies go with their workspace and
 * are pruned after socialhq.ai_graphics.keep_results_days.
 */
final class AiGraphicResults
{
    public const string DISK = 'local';

    public static function disk(): FilesystemAdapter
    {
        return Storage::disk(self::DISK);
    }

    public static function directory(string $workspaceId): string
    {
        return "socialhq/ai-graphics/results/{$workspaceId}";
    }

    public static function store(AiGraphic $graphic, string $bytes, string $extension): string
    {
        $path = self::directory((string) $graphic->workspace_id)."/{$graphic->id}.{$extension}";
        self::disk()->put($path, $bytes);

        return $path;
    }

    /**
     * How long a copy is kept: at least as long as Recent AI graphics lists it.
     */
    public static function keepDays(): int
    {
        return max(RecentAiGraphics::DAYS, (int) config('socialhq.ai_graphics.keep_results_days', 30));
    }

    /**
     * Delete the copies older than keepDays(), and forget their paths.
     */
    public static function prune(): int
    {
        $count = 0;

        AiGraphic::query()
            ->whereNotNull('result_path')
            ->where('created_at', '<', now()->subDays(self::keepDays()))
            ->lazyById()
            ->each(function (AiGraphic $graphic) use (&$count): void {
                self::disk()->delete((string) $graphic->result_path);
                $graphic->update(['result_path' => null]);
                $count++;
            });

        return $count;
    }

    /**
     * The copies go with their workspace (whose rows cascade in the database, firing no model events).
     */
    public static function registerCleanup(): void
    {
        Workspace::deleting(function (Workspace $workspace): void {
            $directory = self::directory((string) $workspace->id);
            // Copies stored before the per-workspace layout.
            $older = AiGraphic::query()
                ->where('workspace_id', $workspace->id)
                ->whereNotNull('result_path')
                ->where('result_path', 'not like', "{$directory}/%")
                ->pluck('result_path')
                ->all();

            DB::afterCommit(function () use ($directory, $older): void {
                self::disk()->deleteDirectory($directory);
                self::disk()->delete($older);
            });
        });
    }
}
