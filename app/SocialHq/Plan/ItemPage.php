<?php

declare(strict_types=1);

namespace App\SocialHq\Plan;

use App\Enums\SocialAccount\Platform;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Ai\AiRouter;
use App\SocialHq\Ai\AiTask;
use App\Enums\Media\Type as MediaType;
use App\SocialHq\Production\Brief;
use App\SocialHq\Production\BriefFile;
use App\SocialHq\Production\BriefRounds;
use App\SocialHq\Production\BriefTeam;

/**
 * What the item page shows: the item, its copy versions and review thread.
 */
final class ItemPage
{
    /**
     * @return array<string, mixed>
     */
    public static function props(PlanItem $item, User $user, Workspace $workspace): array
    {
        $item->load(['plan', 'owner.avatarMedia', 'event', 'socialAccount', 'versions.author.avatarMedia', 'reviews.user.avatarMedia', 'reviews.version', 'brief.assignee.avatarMedia', 'brief.files.uploader.avatarMedia']);

        return [
            'item' => self::item($item),
            'versions' => $item->versions->map(fn (ItemVersion $version): array => self::version($version))->values()->all(),
            'thread' => $item->reviews->map(fn (ItemReview $review): array => self::review($review))->values()->all(),
            'brief' => $item->brief === null ? null : self::brief($item->brief),
            'briefKind' => $item->creative->briefKind()?->value,
            'team' => BriefTeam::members($workspace)->map(fn (User $member): ?array => self::person($member))->values()->all(),
            'abilities' => PlanAccess::abilities($user, $workspace),
            'aiAvailable' => AiRouter::isAvailable(AiTask::Write),
        ];
    }

    /**
     * @return array{id: string, name: string, photo_url: ?string}|null
     */
    public static function person(?User $user): ?array
    {
        return $user === null ? null : [
            'id' => $user->id,
            'name' => (string) $user->name,
            'photo_url' => $user->photo_url,
        ];
    }

    /**
     * The longest caption the item's channel takes: the connected account's
     * cap, else the platform's.
     */
    public static function maxLength(PlanItem $item): ?int
    {
        if ($item->socialAccount !== null) {
            return $item->socialAccount->maxContentLength();
        }

        return Platform::tryFrom((string) $item->platform)?->maxContentLength();
    }

    /**
     * @return array<string, mixed>
     */
    private static function item(PlanItem $item): array
    {
        $platform = Platform::tryFrom((string) $item->platform);
        $account = $item->socialAccount;

        return [
            'id' => $item->id,
            'title' => $item->title,
            'scheduled_on' => $item->scheduled_on->toDateString(),
            'month' => $item->plan->month->format('Y-m'),
            'platform' => $item->platform,
            'platform_label' => $platform?->label(),
            'content_type' => $item->content_type?->value,
            'content_type_label' => $item->content_type?->label(),
            'creative' => $item->creative->value,
            'pillar' => $item->pillar,
            'objective' => $item->objective,
            'angle' => $item->angle,
            'status' => $item->status->value,
            'copy_status' => $item->copy_status->value,
            'post_created_at' => $item->post_created_at?->toIso8601String(),
            'max_length' => self::maxLength($item),
            'owner' => self::person($item->owner),
            'channel' => $account === null ? null : [
                'id' => $account->id,
                'name' => $account->display_name ?: $account->username,
                'platform' => $account->platform->value,
                'avatar_url' => $account->avatar_url,
            ],
            'event' => $item->event === null ? null : [
                'name' => $item->event->name,
                'starts_on' => $item->event->starts_on->toDateString(),
                'verified' => $item->event->isVerified(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function brief(Brief $brief): array
    {
        return [
            'id' => $brief->id,
            'kind' => $brief->kind->value,
            'stage' => $brief->stage->value,
            'assignee' => self::person($brief->assignee),
            'due_on' => $brief->due_on?->toDateString(),
            'format_notes' => $brief->format_notes,
            'on_screen_text' => $brief->on_screen_text,
            'direction' => $brief->direction,
            'references' => $brief->references,
            'approved_at' => $brief->approved_at?->toIso8601String(),
            'current_round' => (int) $brief->files->max('round'),
            'accepts_uploads' => BriefRounds::acceptsUploads($brief),
            'rounds' => $brief->files
                ->groupBy('round')
                ->sortKeysDesc()
                ->map(fn ($files, int $round): array => [
                    'round' => $round,
                    'files' => $files->map(fn (BriefFile $file): array => self::file($brief, $file))->values()->all(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function file(Brief $brief, BriefFile $file): array
    {
        return [
            'id' => $file->id,
            'name' => $file->original_name,
            'mime_type' => $file->mime_type,
            'type' => MediaType::fromMime($file->mime_type)?->value,
            'size' => $file->size,
            'note' => $file->note,
            'url' => route('socialhq.plan.items.brief.files.show', ['item' => $brief->item_id, 'file' => $file->id]),
            'uploader' => $file->relationLoaded('uploader') ? self::person($file->uploader) : null,
            'created_at' => $file->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function version(ItemVersion $version): array
    {
        return [
            'id' => $version->id,
            'number' => $version->number,
            'caption' => $version->caption,
            'hashtags' => $version->hashtags,
            'script' => $version->script,
            'notes' => $version->notes,
            'source' => $version->source->value,
            'author' => self::person($version->author),
            'created_at' => $version->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function review(ItemReview $review): array
    {
        return [
            'id' => $review->id,
            'kind' => $review->kind->value,
            'body' => $review->body,
            'about' => $review->brief_id !== null ? 'creative' : ($review->version_id !== null ? 'copy' : null),
            'version_number' => $review->version?->number,
            'round' => $review->round,
            'user' => self::person($review->user),
            'created_at' => $review->created_at?->toIso8601String(),
        ];
    }
}
