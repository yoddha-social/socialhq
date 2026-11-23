<?php

declare(strict_types=1);

namespace App\SocialHq\Plan;

use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The link between a plan item and the posts made from it. "Create post"
 * hands the item to the composer; saving the post links it here
 * (PostController@store, PATCHES.md row 260), so a composer closed without
 * saving leaves the item as it was. The item reads its posts' statuses live,
 * and a post card names the item it came from.
 */
final class ItemPosts
{
    public const string TABLE = 'shq_plan_item_posts';

    /** The plan items' table, for the post store request's rule. */
    public const string ITEMS_TABLE = 'shq_plan_items';

    /**
     * Which status an item shows when its posts differ: a failed post first
     * (someone has to act), then the one furthest along.
     *
     * @var list<PostStatus>
     */
    private const array SHOWN_FIRST = [
        PostStatus::Failed,
        PostStatus::Published,
        PostStatus::Publishing,
        PostStatus::Scheduled,
        PostStatus::PendingApproval,
        PostStatus::Draft,
    ];

    /**
     * Links the posts just saved to the item they were made from, and
     * records when the item got its first post. A post already linked to an
     * item keeps that link.
     *
     * @param  Collection<int, Post>  $posts
     */
    public static function link(PlanItem $item, Collection $posts, User $user): void
    {
        if ($posts->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($item, $posts, $user): void {
            DB::table(self::TABLE)->insertOrIgnore($posts->map(fn (Post $post): array => [
                'workspace_id' => $item->workspace_id,
                'plan_item_id' => $item->id,
                'post_id' => $post->id,
                'linked_by' => $user->id,
                'created_at' => now(),
            ])->values()->all());

            if ($item->post_created_at === null) {
                $item->forceFill(['post_created_at' => now()])->save();
            }
        });
    }

    /**
     * The status an item shows for its posts, or null when it has none.
     *
     * @param  iterable<PostStatus|string>  $statuses
     */
    public static function shownStatus(iterable $statuses): ?string
    {
        $present = collect($statuses)->map(fn (PostStatus|string $status): PostStatus => $status instanceof PostStatus ? $status : PostStatus::from($status));

        foreach (self::SHOWN_FIRST as $status) {
            if ($present->contains($status)) {
                return $status->value;
            }
        }

        return null;
    }

    /**
     * The item page's list of its posts, oldest first.
     *
     * @return list<array{id: string, status: string, status_label: string, scheduled_at: string|null, published_at: string|null, channel: string|null, platform: string|null, url: string, network_url: string|null}>
     */
    public static function forItemPage(PlanItem $item): array
    {
        return $item->posts()
            ->with('socialAccount')
            ->orderBy(self::TABLE.'.created_at')
            ->orderBy('posts.created_at')
            ->get()
            ->map(fn (Post $post): array => [
                'id' => $post->id,
                'status' => $post->status->value,
                'status_label' => $post->status->label(),
                'scheduled_at' => $post->scheduled_at?->toIso8601String(),
                'published_at' => $post->published_at?->toIso8601String(),
                'channel' => $post->socialAccount?->display_label ?? $post->platform_name,
                'platform' => $post->platform?->value,
                'url' => route('app.posts.edit', $post),
                'network_url' => $post->platform_url,
            ])
            ->values()
            ->all();
    }
}
