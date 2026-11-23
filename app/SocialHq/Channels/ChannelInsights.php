<?php

declare(strict_types=1);

namespace App\SocialHq\Channels;

use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsSyncState;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A channel's Insights history: the posts read from the network with their
 * daily numbers, the follower counts and how far reading got. TryPost keeps it
 * per identity (workspace, network, account id on the network), so rows of an
 * earlier connection of the same account count too.
 *
 * Disconnecting deletes it (`DisconnectSocialAccount`): Insights, its export
 * and the planner's recent posts stop counting a channel that is gone, and a
 * network's data deletion request leaves none of the account's data behind.
 * It stays while another channel of the workspace is the same account.
 */
final class ChannelInsights
{
    /**
     * How many posts with numbers a disconnect deletes, or null when it
     * deletes no Insights history.
     */
    public static function count(SocialAccount $channel): ?int
    {
        if (self::isShared($channel)) {
            return null;
        }

        $posts = self::rows(AnalyticsPublication::query(), $channel)->count();

        if ($posts === 0 && ! self::rows(AnalyticsAccountDailySnapshot::query(), $channel)->exists()) {
            return null;
        }

        return $posts;
    }

    /**
     * Runs inside the disconnect's transaction, before the account goes.
     * Publication daily snapshots go with their publications (cascade).
     */
    public static function delete(SocialAccount $channel): void
    {
        AnalyticsSyncState::query()->where('social_account_id', $channel->id)->delete();

        if (self::isShared($channel)) {
            return;
        }

        self::rows(AnalyticsPublication::query(), $channel)->delete();
        self::rows(AnalyticsAccountDailySnapshot::query(), $channel)->delete();
        self::rows(AnalyticsSyncState::query(), $channel)->delete();
    }

    /**
     * The rows of the channel's identity in its workspace, and any row still
     * linked to the channel itself.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private static function rows(Builder $query, SocialAccount $channel): Builder
    {
        return $query->where(fn (Builder $rows): Builder => $rows
            ->where(AnalyticsSyncState::providerIdentityFor($channel))
            ->orWhere('social_account_id', $channel->id));
    }

    /**
     * Another channel of the workspace is the same account on the same
     * network (Instagram directly and through a Facebook Page share one).
     */
    private static function isShared(SocialAccount $channel): bool
    {
        $network = $channel->platform->network();

        return SocialAccount::query()
            ->where('workspace_id', $channel->workspace_id)
            ->where('platform_user_id', $channel->platform_user_id)
            ->whereKeyNot($channel->id)
            ->get(['id', 'platform'])
            ->contains(fn (SocialAccount $other): bool => $other->platform->network() === $network);
    }
}
