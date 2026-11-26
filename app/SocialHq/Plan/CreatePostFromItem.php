<?php

declare(strict_types=1);

namespace App\SocialHq\Plan;

use App\Enums\Media\Type as MediaType;
use App\Enums\PostPlatform\ContentType;
use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Knowledge\Audience\Audience;
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
 * when it needs one and no approver let it go out text-only) to TryPost's
 * composer. The approved round's files are imported into the workspace's
 * uploads like an AI graphic is (a finished media import with an upload
 * token), so the composer attaches them; the post itself then follows
 * TryPost's approval rules. Only files the item's format can carry go (no
 * PDF on a Facebook post); the others are named.
 */
final class CreatePostFromItem
{
    public const string BLOCKED_BY_COPY = 'copy';

    public const string BLOCKED_BY_PLACEHOLDERS = 'placeholders';

    public const string BLOCKED_BY_CREATIVE = 'creative';

    public static function isReady(PlanItem $item): bool
    {
        return self::blockers($item) === [];
    }

    /**
     * Everything that stands between the item and its post, all at once:
     * copy not approved, gaps ("[city]") left in the copy, a creative not
     * approved. An approver can let the post go out text-only, and then the
     * creative no longer counts.
     *
     * @return list<string>
     */
    public static function blockers(PlanItem $item): array
    {
        $blockers = [];

        if ($item->copy_status !== CopyStatus::Approved) {
            $blockers[] = self::BLOCKED_BY_COPY;
        }

        if (Placeholders::inVersion($item->latestVersion) !== []) {
            $blockers[] = self::BLOCKED_BY_PLACEHOLDERS;
        }

        if (self::needsCreative($item) && $item->brief?->stage !== BriefStage::Approved) {
            $blockers[] = self::BLOCKED_BY_CREATIVE;
        }

        return $blockers;
    }

    /**
     * Whether the post waits for a creative: the item has something to make
     * and no approver has let it go out text-only (a waiver counts only on a
     * format that can go out without media).
     */
    public static function needsCreative(PlanItem $item): bool
    {
        return $item->creative->briefKind() !== null
            && ($item->creative_waived_at === null || ! self::mayGoTextOnly($item));
    }

    /**
     * Whether the item's format can be posted without an image or video
     * (TryPost's own rule, ContentType::requiresMedia: a LinkedIn or X post
     * can, an Instagram feed post or a reel can't). An item without a format
     * can.
     */
    public static function mayGoTextOnly(PlanItem $item): bool
    {
        return $item->content_type === null || ! $item->content_type->requiresMedia();
    }

    /**
     * An approver lets the post go out without its creative (or takes that
     * back). The brief stays: when its creative is approved before the post
     * is created, the post still gets the files. Only on a format that can go
     * out without media.
     */
    public static function waiveCreative(PlanItem $item, User $user, bool $waive = true): void
    {
        if ($waive && $item->creative->briefKind() === null) {
            throw ValidationException::withMessages(['creative' => __('socialhq_production.post.errors.nothing_to_waive')]);
        }

        if ($waive && ! self::mayGoTextOnly($item)) {
            throw ValidationException::withMessages(['creative' => __('socialhq_production.post.errors.needs_media', ['format' => $item->content_type->label()])]);
        }

        $item->forceFill([
            'creative_waived_at' => $waive ? now() : null,
            'creative_waived_by' => $waive ? $user->id : null,
        ])->save();
    }

    /**
     * The post's time is the item's posting time as a wall clock in the
     * user's zone (what the composer takes), or null without one (the
     * composer then uses 09:00 of the day). When both are set, scheduled_at
     * wins: it is the instant the audience should see the post, which may
     * fall on the day before or after scheduled_on in the user's zone.
     *
     * Nothing is recorded here: the composer sends the item with the post,
     * and saving links them (ItemPosts), so a composer closed without saving
     * leaves the item as it was.
     *
     * @return array{content: string, media: Collection<int, Media>, import_ids: list<string>, left_out: list<string>, scheduled_on: string, scheduled_at: string, social_account_id: ?string}
     */
    public static function execute(PlanItem $item, User $user): array
    {
        $item->loadMissing(['brief', 'latestVersion', 'socialAccount', 'workspace']);

        $blockers = self::blockers($item);

        if ($blockers !== [] || $item->latestVersion === null) {
            throw ValidationException::withMessages(['post' => in_array(self::BLOCKED_BY_PLACEHOLDERS, $blockers, true)
                ? __('socialhq_production.post.errors.placeholders', ['gaps' => implode(', ', Placeholders::inVersion($item->latestVersion))])
                : __('socialhq_production.post.errors.not_ready')]);
        }

        $media = collect();
        $importIds = [];
        [$files, $leftOut] = self::finalFiles($item)->partition(fn (BriefFile $file): bool => self::fits($file, $item->content_type));

        foreach ($files as $file) {
            $imported = self::import($file, $user, $item->workspace);

            if ($imported !== null) {
                [$importIds[], $row] = $imported;
                $media->push($row);
            }
        }

        $version = $item->latestVersion;

        return [
            'content' => collect([$version->caption, $version->hashtags])->filter(fn (?string $part): bool => filled($part))->implode("\n\n"),
            'media' => $media,
            'import_ids' => $importIds,
            'left_out' => $leftOut->pluck('original_name')->values()->all(),
            'scheduled_on' => $item->scheduled_on->toDateString(),
            'scheduled_at' => self::postingTime($item, $user),
            'social_account_id' => $item->socialAccount?->workspace_id === $item->workspace_id ? $item->socialAccount->id : null,
        ];
    }

    /**
     * The item's time as a wall clock in the user's zone, as the composer takes
     * it. An item without a time goes out at 09:00 where most of the audience
     * is (QA 2026-11-18, item 13), not at 09:00 for whoever creates the post.
     */
    private static function postingTime(PlanItem $item, User $user): string
    {
        $userZone = Timezone::normalize($user->timezone);

        return SuggestedTime::wallClockIn($item, $userZone)
            ?? SuggestedTime::at($item->scheduled_on->toDateString(), '09:00', Audience::mainZone(PlanOptions::profile($item->workspace), $user->timezone))
                ->setTimezone($userZone)
                ->format('Y-m-d\TH:i');
    }

    /**
     * The files of the approved round.
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
            ->get();
    }

    /**
     * Whether a post of this format can carry the file, by the content type's
     * own media rules (an item without a format keeps every media file).
     */
    public static function fits(BriefFile $file, ?ContentType $contentType): bool
    {
        $type = MediaType::fromMime($file->mime_type);

        if ($type === null || $contentType === null) {
            return $type !== null;
        }

        return match ($type) {
            MediaType::Image => $contentType->supportsImage() && (! MediaType::isGif($file->mime_type) || $contentType->acceptsGif()),
            MediaType::Video => $contentType->supportsVideo() && (! MediaType::isMov($file->mime_type, $file->original_name) || $contentType->acceptsMov()),
            MediaType::Document => $contentType->supportsDocument(),
        };
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
