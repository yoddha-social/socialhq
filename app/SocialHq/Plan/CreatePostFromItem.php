<?php

declare(strict_types=1);

namespace App\SocialHq\Plan;

use App\Enums\Media\Type as MediaType;
use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Production\BriefFile;
use App\SocialHq\Production\BriefStage;
use App\SocialHq\Production\BriefStorage;
use App\Support\MediaImportStatus;
use App\Support\Timezone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Create post": hands an item with approved copy (and an approved creative,
 * when it needs one) to TryPost's composer. The approved round's files are
 * imported into the workspace's uploads like an AI graphic is (a finished
 * media import with an upload token), so the composer attaches them; the
 * post itself then follows TryPost's approval rules.
 */
final class CreatePostFromItem
{
    public static function isReady(PlanItem $item): bool
    {
        if ($item->copy_status !== CopyStatus::Approved) {
            return false;
        }

        if ($item->creative->briefKind() === null) {
            return true;
        }

        return $item->brief?->stage === BriefStage::Approved;
    }

    /**
     * The post's time is the item's posting time as a wall clock in the
     * user's zone (what the composer takes), or null without one (the
     * composer then uses 09:00 of the day).
     *
     * @return array{content: string, media: Collection<int, Media>, import_ids: list<string>, scheduled_on: string, scheduled_at: ?string, social_account_id: ?string}
     */
    public static function execute(PlanItem $item, User $user): array
    {
        $item->loadMissing(['brief', 'latestVersion', 'socialAccount', 'workspace']);

        if (! self::isReady($item) || $item->latestVersion === null) {
            throw ValidationException::withMessages(['post' => __('socialhq_production.post.errors.not_ready')]);
        }

        $media = collect();
        $importIds = [];

        foreach (self::finalFiles($item) as $file) {
            $imported = self::import($file, $user, $item->workspace);

            if ($imported !== null) {
                [$importIds[], $row] = $imported;
                $media->push($row);
            }
        }

        $item->forceFill(['post_created_at' => now()])->save();

        $version = $item->latestVersion;

        return [
            'content' => collect([$version->caption, $version->hashtags])->filter(fn (?string $part): bool => filled($part))->implode("\n\n"),
            'media' => $media,
            'import_ids' => $importIds,
            'scheduled_on' => $item->scheduled_on->toDateString(),
            'scheduled_at' => SuggestedTime::wallClockIn($item, Timezone::normalize($user->timezone)),
            'social_account_id' => $item->socialAccount?->workspace_id === $item->workspace_id ? $item->socialAccount->id : null,
        ];
    }

    /**
     * The files of the approved round that a post can carry.
     *
     * @return Collection<int, BriefFile>
     */
    private static function finalFiles(PlanItem $item): Collection
    {
        $brief = $item->brief;

        if ($brief === null || $brief->stage !== BriefStage::Approved) {
            return collect();
        }

        return BriefFile::query()
            ->where('brief_id', $brief->id)
            ->where('round', $brief->currentRound())
            ->orderBy('id')
            ->get()
            ->filter(fn (BriefFile $file): bool => MediaType::fromMime($file->mime_type) !== null)
            ->values();
    }

    /**
     * @return array{0: string, 1: Media}|null
     */
    private static function import(BriefFile $file, User $user, Workspace $workspace): ?array
    {
        $media = self::earlierImport($file, $workspace) ?? self::copyIntoUploads($file, $workspace);

        if ($media === null) {
            return null;
        }

        $importId = (string) Str::uuid();
        MediaImportStatus::pending($importId, $user->id, $workspace->id);
        MediaImportStatus::complete($importId, $media);

        return [$importId, $media];
    }

    /**
     * The upload an earlier "Create post" made of this file, while it is still
     * a loose workspace upload (not yet taken by a post or idea) with its file.
     */
    private static function earlierImport(BriefFile $file, Workspace $workspace): ?Media
    {
        $media = $workspace->media()
            ->where('collection', Media::COLLECTION_UPLOADS)
            ->whereNull('post_id')
            ->whereNull('idea_id')
            ->whereNotNull('upload_token')
            ->where('meta->source', 'socialhq_brief')
            ->where('meta->brief_file_id', (string) $file->id)
            ->latest('id')
            ->first();

        return $media !== null && Storage::exists($media->path) ? $media : null;
    }

    private static function copyIntoUploads(BriefFile $file, Workspace $workspace): ?Media
    {
        $stream = BriefStorage::disk()->readStream($file->path);

        if ($stream === null) {
            return null;
        }

        $path = tempnam(sys_get_temp_dir(), 'shq-brief-file-');

        try {
            $target = fopen($path, 'wb');
            stream_copy_to_stream($stream, $target);
            fclose($target);

            $media = $workspace->addMediaFromPath(
                $path,
                $file->original_name,
                Media::COLLECTION_UPLOADS,
                ['source' => 'socialhq_brief', 'brief_file_id' => $file->id],
                mimeType: $file->mime_type,
            );
            $media->issueUploadToken();
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }

            @unlink($path);
        }

        return $media;
    }
}
