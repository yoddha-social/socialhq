<?php

declare(strict_types=1);

namespace App\SocialHq\Plan;

use App\Actions\Post\ReschedulePost;
use App\Actions\Post\UpdatePost;
use App\Enums\Post\Action as PostAction;
use App\Enums\Post\ScheduleAction;
use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostApproval;
use App\Support\Timezone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The link between a plan item and the posts made from it. "Create post"
 * hands the item to the composer; saving the post links it here
 * (PostController@store, PATCHES.md row 260), so a composer closed without
 * saving leaves the item as it was. The item reads its posts' statuses live,
 * and a post card names the item it came from.
 *
 * The plan keeps its posts in step: dropping or deleting an item whose post
 * will still go out asks whether it goes back to drafts, and an item moved
 * to another day offers to move its posts there too. Both go through
 * TryPost's own actions (Publish's "Move to drafts" and the calendar's
 * reschedule), so the person's rights and the approval rules still decide,
 * and both say what they did: a post that stayed put, or one a member's move
 * sent back for approval, is named (X13: a moved post that silently waited
 * for approval could go out on neither day).
 */
final class ItemPosts
{
    public const string TABLE = 'shq_plan_item_posts';

    /** The plan items' table, for the post store request's rule. */
    public const string ITEMS_TABLE = 'shq_plan_items';

    /** What a drop or delete does with posts that will still go out. */
    public const string TO_DRAFTS = 'drafts';

    public const string KEEP = 'keep';

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
     * A post that will still go out unless someone stops it.
     *
     * @var list<PostStatus>
     */
    private const array UPCOMING = [PostStatus::Scheduled, PostStatus::PendingApproval];

    /**
     * A post that can follow its item to another day (ReschedulePost refuses
     * one waiting for approval).
     *
     * @var list<PostStatus>
     */
    private const array MOVABLE = [PostStatus::Draft, PostStatus::Scheduled];

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
     * @return list<array{id: string, status: string, status_label: string, scheduled_at: string|null, published_at: string|null, channel: string|null, platform: string|null, url: string, network_url: string|null, off_day: bool, back_to_approval: bool, other_channel: bool, other_format: bool}>
     */
    public static function forItemPage(PlanItem $item, User $user, Workspace $workspace): array
    {
        $posts = $item->posts()
            ->with('socialAccount')
            ->orderBy(self::TABLE.'.created_at')
            ->orderBy('posts.created_at')
            ->get();
        $backToApproval = self::backToApprovalOnMove($item->setRelation('posts', $posts), $user, self::movesNeedApproval($workspace, $user))
            ->pluck('id')
            ->all();

        return $posts
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
                'off_day' => self::isOffDay($item, $post),
                // Moving it to the item's day sends it back for approval (the page warns first).
                'back_to_approval' => in_array($post->id, $backToApproval, true),
                // The item was given another channel or format after the post was made.
                'other_channel' => $item->social_account_id !== null && $post->social_account_id !== $item->social_account_id,
                'other_format' => $item->content_type !== null && $post->content_type !== null && $post->content_type !== $item->content_type,
            ])
            ->values()
            ->all();
    }

    /**
     * The item's posts that will still go out (scheduled, or waiting for
     * approval), soonest first. Dropping or deleting the item asks what
     * happens to them.
     *
     * @return Collection<int, Post>
     */
    public static function upcoming(PlanItem $item): Collection
    {
        return $item->posts()
            ->with('socialAccount')
            ->whereIn('posts.status', self::UPCOMING)
            ->orderBy('posts.scheduled_at')
            ->get();
    }

    /**
     * The month page's short list of an item's upcoming posts, for the drop
     * and delete dialog. Reads the loaded posts, so the page asks once.
     *
     * @return list<array{id: string, status: string, scheduled_at: string|null, channel: string|null, platform: string|null}>
     */
    public static function upcomingForPage(PlanItem $item): array
    {
        return $item->posts
            ->filter(fn (Post $post): bool => in_array($post->status, self::UPCOMING, true))
            ->sortBy(fn (Post $post): string => $post->scheduled_at?->toIso8601ZuluString() ?? '')
            ->map(fn (Post $post): array => [
                'id' => $post->id,
                'status' => $post->status->value,
                'scheduled_at' => $post->scheduled_at?->toIso8601String(),
                'channel' => $post->socialAccount?->display_label ?? $post->platform_name,
                'platform' => $post->platform?->value,
            ])
            ->values()
            ->all();
    }

    /**
     * Moves the item's upcoming posts to drafts, as Publish's "Move to drafts"
     * does. A post the person may not change (another member's request for
     * approval) or one already going out keeps its schedule; the count of
     * those comes back, so the page can say they can still go out. Each post
     * is read on its own, as TryPost's controllers hand it over.
     */
    public static function toDrafts(PlanItem $item, User $user): int
    {
        $kept = 0;

        foreach (self::upcoming($item) as $listed) {
            $post = $listed->fresh();

            if ($post === null) {
                continue;
            }

            if (Gate::forUser($user)->denies('update', $post)
                || UpdatePost::execute($item->workspace, $post, ScheduleAction::Draft->postChanges(), $user)['action'] === PostAction::Finalized) {
                $kept++;
            }
        }

        return $kept;
    }

    /**
     * Whether the post is a dated draft or a scheduled post whose day isn't
     * the item's, read in the item's posting zone (else the channel's).
     */
    public static function isOffDay(PlanItem $item, Post $post): bool
    {
        return in_array($post->status, self::MOVABLE, true)
            && $post->scheduled_at !== null
            && CarbonImmutable::parse($post->scheduled_at)->setTimezone(self::zone($item, $post))->toDateString() !== $item->scheduled_on->toDateString();
    }

    /**
     * How many of the item's posts are off its day.
     */
    public static function offDayCount(PlanItem $item): int
    {
        return $item->posts->filter(fn (Post $post): bool => self::isOffDay($item, $post))->count();
    }

    /**
     * Whether the person's change to a scheduled post needs approval, as
     * UpdatePost asks PostApproval: moving an approved post sends it back.
     */
    public static function movesNeedApproval(Workspace $workspace, User $user): bool
    {
        return PostApproval::isRequired($workspace, $user, PostStatus::Scheduled->value);
    }

    /**
     * The item's approved posts that moving to its day would send back for
     * approval: scheduled posts off its day that the person may change, when
     * their moves need approval. Reads the loaded posts; the warning before
     * the move names them.
     *
     * @return Collection<int, Post>
     */
    public static function backToApprovalOnMove(PlanItem $item, User $user, bool $movesNeedApproval): Collection
    {
        if (! $movesNeedApproval) {
            return new Collection;
        }

        return $item->posts
            ->filter(fn (Post $post): bool => $post->status === PostStatus::Scheduled
                && self::isOffDay($item, $post)
                && Gate::forUser($user)->allows('update', $post))
            ->sortBy(fn (Post $post): string => $post->scheduled_at?->toIso8601ZuluString() ?? '')
            ->values()
            ->toBase();
    }

    /**
     * Moves the item's posts that are off its day to that day, at the same
     * time of day, through the calendar's reschedule: a draft stays a draft,
     * a scheduled post goes through UpdatePost and the approval rules. Each
     * one is counted by what happened: moved (and still scheduled, or a
     * draft), moved but waiting for approval (a member's change to an
     * approved post), or kept at its time (the person may not change it, the
     * reschedule refused it as about to go out or past, or it went out
     * meanwhile).
     *
     * @return array{moved: int, pending: int, kept: int}
     */
    public static function followItem(PlanItem $item, User $user): array
    {
        $moved = 0;
        $pending = 0;
        $kept = 0;

        foreach ($item->posts()->with('socialAccount')->get() as $post) {
            if (! self::isOffDay($item, $post)) {
                continue;
            }

            if (Gate::forUser($user)->denies('update', $post)) {
                $kept++;

                continue;
            }

            $zone = self::zone($item, $post);
            $time = CarbonImmutable::parse($post->scheduled_at)->setTimezone($zone)->format('H:i:s');
            $target = CarbonImmutable::parse("{$item->scheduled_on->toDateString()} {$time}", $zone);

            try {
                // Read on its own, as TryPost's controllers hand a post over.
                $action = ReschedulePost::execute($item->workspace, $post->fresh() ?? $post, $target->utc()->toIso8601ZuluString(), $user)['action'];
            } catch (ValidationException) {
                $kept++;

                continue;
            }

            match ($action) {
                PostAction::PendingApproval => $pending++,
                PostAction::Finalized => $kept++,
                default => $moved++,
            };
        }

        return ['moved' => $moved, 'pending' => $pending, 'kept' => $kept];
    }

    /**
     * The zone the item's day is read in: its posting time's zone, else the
     * post's channel zone.
     */
    private static function zone(PlanItem $item, Post $post): string
    {
        return Timezone::normalize($item->suggested_timezone ?? $post->socialAccount?->timezone);
    }
}
