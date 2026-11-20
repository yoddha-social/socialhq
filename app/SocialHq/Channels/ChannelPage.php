<?php

declare(strict_types=1);

namespace App\SocialHq\Channels;

use App\Actions\SocialAccount\CountPostsSentThisWeek;
use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Uri;

/**
 * What the channel page shows beside TryPost's settings: where the channel
 * stands (four counts and the week's goal) and its name in Social HQ.
 */
class ChannelPage
{
    /**
     * The window of the Scheduled and Published tiles, in days.
     */
    public const WINDOW_DAYS = 30;

    /**
     * The longest name a channel may be given.
     */
    public const NAME_MAX_LENGTH = 60;

    /**
     * @return array{stats: array{scheduled: int, pending_approval: int, drafts: int, published: int, waiting: int}, week: array{sent: int, goal: int|null}, name: array{custom: string|null, network: string}, invite: array{supported: bool, reconnecting: bool}}
     */
    public static function props(User $viewer, SocialAccount $channel): array
    {
        return [
            'stats' => self::stats($viewer, $channel),
            'week' => [
                'sent' => CountPostsSentThisWeek::handle($channel, $viewer->week_starts_on),
                'goal' => $channel->posting_goal,
            ],
            'name' => [
                'custom' => self::customName($channel),
                'network' => $channel->display_name ?: $channel->username ?: $channel->platform->label(),
            ],
            'invite' => [
                'supported' => ConnectInvite::entryFor($channel->platform) !== null,
                'reconnecting' => $channel->isDisconnected(),
            ],
        ];
    }

    /**
     * The name the team gave the channel, or null when it uses the network's.
     * Read from the raw attributes: a channel loaded with a column list has no
     * `shq_name`, and that must not throw under strict models.
     */
    public static function customName(SocialAccount $channel): ?string
    {
        $name = data_get($channel->getAttributes(), 'shq_name');

        return is_string($name) && filled($name) ? $name : null;
    }

    public static function rename(SocialAccount $channel, ?string $name): void
    {
        $channel->forceFill(['shq_name' => filled($name) ? $name : null])->save();
    }

    /**
     * After a disconnect started on the channel's own page there is no page to
     * go back to: the next channel's page, or the channel list when none is left.
     */
    public static function afterDisconnect(Request $request, SocialAccount $channel): ?RedirectResponse
    {
        $previous = Uri::of(url()->previous())->path();
        $own = Uri::of(route('app.channels.settings', $channel))->path();

        if ($previous !== $own) {
            return null;
        }

        $next = $request->user()->currentWorkspace->socialAccounts()->whereKeyNot($channel->id)->first();

        return $next
            ? redirect()->route('app.channels.settings', $next)
            : redirect()->route('app.workspace.channels');
    }

    /**
     * @return array{scheduled: int, pending_approval: int, drafts: int, published: int, waiting: int}
     */
    private static function stats(User $viewer, SocialAccount $channel): array
    {
        $posts = fn (): Builder => Post::query()->where('posts.social_account_id', $channel->id);
        $waiting = fn (): Builder => $posts()->whereIn('posts.status', [PostStatus::Scheduled, PostStatus::Publishing]);

        return [
            'scheduled' => $waiting()->where(fn (Builder $due): Builder => $due
                ->whereNull('posts.scheduled_at')
                ->orWhere('posts.scheduled_at', '<=', now()->addDays(self::WINDOW_DAYS)))->count(),
            'pending_approval' => $posts()->pendingApproval()->visiblePendingApprovalsFor($viewer)->count(),
            'drafts' => $posts()->where('posts.status', PostStatus::Draft)->count(),
            'published' => $posts()->publicationPublished()->where('posts.published_at', '>=', now()->subDays(self::WINDOW_DAYS))->count(),
            'waiting' => $waiting()->count(),
        ];
    }
}
