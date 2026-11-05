<?php

declare(strict_types=1);

namespace App\SocialHq\Plan;

use App\Enums\SocialAccount\Platform;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Ai\AiRouter;
use App\SocialHq\Ai\AiTask;

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
        $item->load(['plan', 'owner.avatarMedia', 'event', 'socialAccount', 'versions.author.avatarMedia', 'reviews.user.avatarMedia', 'reviews.version']);

        return [
            'item' => self::item($item),
            'versions' => $item->versions->map(fn (ItemVersion $version): array => self::version($version))->values()->all(),
            'thread' => $item->reviews->map(fn (ItemReview $review): array => self::review($review))->values()->all(),
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
