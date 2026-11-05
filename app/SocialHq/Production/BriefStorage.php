<?php

declare(strict_types=1);

namespace App\SocialHq\Production;

use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\PlanItem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\MimeTypes;

/**
 * Where brief files live: the private `local` disk, never the default disk
 * (in production a public bucket). They are served only through
 * BriefFileController. Stored names take their extension from the sniffed
 * type, never from the uploaded name.
 */
final class BriefStorage
{
    public const string DISK = 'local';

    public static function disk(): FilesystemAdapter
    {
        return Storage::disk(self::DISK);
    }

    public static function directory(Brief $brief): string
    {
        return "socialhq/briefs/{$brief->id}";
    }

    /**
     * The extension for a sniffed MIME type ("bin" when unknown).
     */
    public static function extensionFor(string $mimeType): string
    {
        return MimeTypes::getDefault()->getExtensions($mimeType)[0] ?? 'bin';
    }

    /**
     * Remove the files of the briefs a query matches, once the surrounding
     * transaction commits (rows go by cascade, files don't).
     *
     * @param  Builder<Brief>  $briefs
     */
    public static function forget(Builder $briefs): void
    {
        $paths = BriefFile::query()->whereIn('brief_id', $briefs->select('id'))->pluck('path')->all();

        if ($paths === []) {
            return;
        }

        DB::afterCommit(fn () => self::disk()->delete($paths));
    }

    /**
     * Delete brief files with the rows that own them: an item, its plan or the brief itself.
     */
    public static function registerCleanup(): void
    {
        Brief::deleting(fn (Brief $brief) => self::forget(Brief::query()->whereKey($brief->id)));
        PlanItem::deleting(fn (PlanItem $item) => self::forget(Brief::query()->where('item_id', $item->id)));
        ContentPlan::deleting(fn (ContentPlan $plan) => self::forget(Brief::query()->whereIn('item_id', PlanItem::query()->where('plan_id', $plan->id)->select('id'))));
    }
}
