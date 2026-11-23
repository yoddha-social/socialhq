<?php

declare(strict_types=1);

namespace App\Actions\SocialAccount;

use App\Actions\Post\DeleteChannelPosts;
use App\Actions\Post\Queue\ReflowChannelQueue;
use App\Exceptions\Post\QueueBusyException;
use App\Models\SocialAccount;
use App\SocialHq\Channels\ChannelInsights;
use Illuminate\Support\Facades\DB;

/**
 * Removes a channel the way "Disconnect" does: its posts first, under the
 * channel's queue lock so nothing is placed on it meanwhile, then its Insights
 * history (Social HQ, `ChannelInsights`), then the account with its tokens.
 * Used by the disconnect button and by a network's request to delete a user's
 * data.
 */
class DisconnectSocialAccount
{
    /**
     * @throws QueueBusyException when the channel's queue is being changed
     */
    public static function execute(SocialAccount $account): void
    {
        ReflowChannelQueue::withLock([$account->id], fn () => DB::transaction(function () use ($account): void {
            DeleteChannelPosts::forAccount($account);
            ChannelInsights::delete($account);

            $account->delete();
        }));
    }
}
