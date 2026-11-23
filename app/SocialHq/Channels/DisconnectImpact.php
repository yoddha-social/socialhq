<?php

declare(strict_types=1);

namespace App\SocialHq\Channels;

use App\Enums\Post\Status as PostStatus;
use App\Enums\Repurpose\Status as RepurposeStatus;
use App\Enums\SocialAccount\Platform;
use App\Models\Media;
use App\Models\Post;
use App\Models\Repurpose;
use App\Models\SocialAccount;
use App\SocialHq\Plan\ItemStatus;
use App\SocialHq\Plan\PlanItem;
use App\Support\Mail\PostExcerpt;
use Illuminate\Database\Eloquent\Builder;

/**
 * What disconnecting a channel deletes from Social HQ, shown in the disconnect
 * dialog before anyone types "disconnect". It counts what
 * `DisconnectSocialAccount` removes: every post of the channel with its media,
 * whatever its status or origin, and its Insights history (`ChannelInsights`).
 * Nothing on the network changes.
 */
final class DisconnectImpact
{
    /**
     * How many of the next scheduled posts the dialog lists.
     */
    public const int UPCOMING = 3;

    public const int EXCERPT_LENGTH = 90;

    /**
     * @return array{posts: array{scheduled: int, pending_approval: int, drafts: int, failed: int, sent: int}, unsent: int, media: int, upcoming: list<array{id: string, scheduled_at: string|null, excerpt: string}>, posting_times: int, posting_goal: int|null, plan_items: int, repurposes: int, insights: int|null, network: string}
     */
    public static function of(SocialAccount $channel): array
    {
        $posts = fn (): Builder => Post::query()->where('posts.social_account_id', $channel->id);
        $byStatus = $posts()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $count = fn (PostStatus ...$statuses): int => (int) collect($statuses)->sum(fn (PostStatus $status): int => (int) $byStatus->get($status->value, 0));

        $counts = [
            'scheduled' => $count(PostStatus::Scheduled, PostStatus::Publishing),
            'pending_approval' => $count(PostStatus::PendingApproval),
            'drafts' => $count(PostStatus::Draft),
            'failed' => $count(PostStatus::Failed),
            'sent' => $count(PostStatus::Published),
        ];

        return [
            'posts' => $counts,
            'unsent' => $counts['scheduled'] + $counts['pending_approval'] + $counts['drafts'] + $counts['failed'],
            'media' => Media::query()->whereIn('post_id', $posts()->select('posts.id'))->count(),
            'upcoming' => self::upcoming($posts()),
            'posting_times' => $channel->posting_schedule?->slotCount() ?? 0,
            'posting_goal' => $channel->posting_goal,
            'plan_items' => PlanItem::query()
                ->where('social_account_id', $channel->id)
                ->where('status', '!=', ItemStatus::Dropped)
                ->whereNull('post_created_at')
                ->whereDate('scheduled_on', '>=', now()->toDateString())
                ->count(),
            'repurposes' => Repurpose::query()
                ->where('source_social_account_id', $channel->id)
                ->whereIn('status', [RepurposeStatus::Active, RepurposeStatus::Paused])
                ->count(),
            'insights' => ChannelInsights::count($channel),
            'network' => self::networkName($channel->platform),
        ];
    }

    /**
     * The network as people call it, where its posts stay.
     */
    public static function networkName(Platform $platform): string
    {
        return match ($platform) {
            Platform::Facebook => 'Facebook',
            Platform::Instagram, Platform::InstagramFacebook => Platform::Instagram->label(),
            Platform::LinkedIn, Platform::LinkedInPage => Platform::LinkedIn->label(),
            Platform::YouTube => 'YouTube',
            default => $platform->label(),
        };
    }

    /**
     * @param  Builder<Post>  $posts
     * @return list<array{id: string, scheduled_at: string|null, excerpt: string}>
     */
    private static function upcoming(Builder $posts): array
    {
        return $posts
            ->where('posts.status', PostStatus::Scheduled)
            ->where('posts.scheduled_at', '>', now())
            ->orderBy('posts.scheduled_at')
            ->limit(self::UPCOMING)
            ->get(['posts.id', 'posts.scheduled_at', 'posts.content'])
            ->map(fn (Post $post): array => [
                'id' => (string) $post->id,
                'scheduled_at' => $post->scheduled_at?->toIso8601String(),
                'excerpt' => PostExcerpt::from($post->content, self::EXCERPT_LENGTH),
            ])
            ->values()
            ->all();
    }
}
