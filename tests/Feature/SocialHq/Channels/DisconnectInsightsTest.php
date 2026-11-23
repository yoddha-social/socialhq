<?php

declare(strict_types=1);

use App\Enums\Analytics\SyncCollector;
use App\Enums\SocialAccount\Platform;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Jobs\SendNotification;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\AnalyticsSyncState;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class, SendNotification::class]);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

/**
 * Insights history of an account: a post with its daily numbers, a follower
 * count and a sync state. `$linked` false is an earlier connection of the
 * same account: no account id, another analytics key, and a sync state for
 * another collector (one state per identity and collector).
 *
 * @return array{publication: AnalyticsPublication, numbers: AnalyticsPublicationDailySnapshot, followers: AnalyticsAccountDailySnapshot, state: AnalyticsSyncState}
 */
function insightsHistory(SocialAccount $account, bool $linked = true, int $followers = 100): array
{
    $identity = AnalyticsSyncState::providerIdentityFor($account);
    $link = [
        'social_account_id' => $linked ? $account->id : null,
        'social_account_key' => $linked ? $account->id : (string) Str::uuid(),
    ];
    $publication = AnalyticsPublication::factory()->create([...$identity, ...$link, 'platform' => $account->platform]);

    return [
        'publication' => $publication,
        'numbers' => AnalyticsPublicationDailySnapshot::factory()->create(['publication_id' => $publication->id]),
        'followers' => AnalyticsAccountDailySnapshot::factory()->create([
            ...$identity,
            ...$link,
            'platform' => $account->platform,
            'date' => now('UTC')->subDay()->toDateString(),
            'followers_count' => $followers,
        ]),
        'state' => AnalyticsSyncState::factory()->create([
            ...AnalyticsSyncState::identityFor($account),
            'social_account_id' => $linked ? $account->id : null,
            'collector' => $linked ? SyncCollector::PublicationBackfill : SyncCollector::PublicationDiscovery,
        ]),
    ];
}

/**
 * @param  array<string, Model>  $history
 */
function insightsHistoryExists(array $history): bool
{
    return collect($history)->every(fn ($row): bool => $row::query()->whereKey($row->getKey())->exists());
}

/**
 * @param  array<string, Model>  $history
 */
function insightsHistoryGone(array $history): bool
{
    return collect($history)->every(fn ($row): bool => ! $row::query()->whereKey($row->getKey())->exists());
}

test('disconnecting deletes the channel’s Insights history, an earlier connection’s too', function () {
    $channel = SocialAccount::factory()->facebook()->create(['workspace_id' => $this->workspace->id]);
    $current = insightsHistory($channel);
    $earlier = insightsHistory($channel, linked: false);

    $sibling = SocialAccount::factory()->facebook()->create(['workspace_id' => $this->workspace->id]);
    $siblingHistory = insightsHistory($sibling);
    $samePageElsewhere = SocialAccount::factory()->facebook()->create(['platform_user_id' => $channel->platform_user_id]);
    $elsewhereHistory = insightsHistory($samePageElsewhere);

    $this->actingAs($this->user)->delete(route('app.channels.disconnect', $channel))->assertRedirect();

    expect(SocialAccount::query()->find($channel->id))->toBeNull()
        ->and(insightsHistoryGone($current))->toBeTrue()
        ->and(insightsHistoryGone($earlier))->toBeTrue()
        ->and(insightsHistoryExists($siblingHistory))->toBeTrue()
        ->and(insightsHistoryExists($elsewhereHistory))->toBeTrue();
});

test('Insights stops counting a disconnected channel', function () {
    $gone = SocialAccount::factory()->facebook()->create(['workspace_id' => $this->workspace->id]);
    $kept = SocialAccount::factory()->facebook()->create(['workspace_id' => $this->workspace->id]);
    insightsHistory($gone, followers: 1306);
    insightsHistory($kept, followers: 1);

    $followers = fn (): mixed => $this->actingAs($this->user)
        ->get(route('app.insights'))
        ->assertOk()
        ->inertiaProps('report.summary.followers.value');

    expect($followers())->toBe(1307);

    $this->actingAs($this->user)->delete(route('app.channels.disconnect', $gone))->assertRedirect();

    expect($followers())->toBe(1);
});

test('the history stays while another channel of the workspace is the same account', function () {
    $direct = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::InstagramFacebook,
        'platform_user_id' => $direct->platform_user_id,
    ]);
    $history = insightsHistory($direct);

    $this->actingAs($this->user)->delete(route('app.channels.disconnect', $direct))->assertRedirect();

    expect(AnalyticsPublication::query()->whereKey($history['publication']->id)->exists())->toBeTrue()
        ->and(AnalyticsAccountDailySnapshot::query()->whereKey($history['followers']->id)->exists())->toBeTrue()
        ->and(AnalyticsSyncState::query()->whereKey($history['state']->id)->exists())->toBeFalse();
});

test('a disconnect that fails keeps the Insights history', function () {
    $channel = SocialAccount::factory()->facebook()->create(['workspace_id' => $this->workspace->id]);
    $history = insightsHistory($channel);
    Event::listen('eloquent.deleting: '.SocialAccount::class, fn () => throw new RuntimeException('delete failed'));

    $this->actingAs($this->user)->delete(route('app.channels.disconnect', $channel))->assertServerError();

    expect(insightsHistoryExists($history))->toBeTrue();
});

test('the prune command deletes the history of accounts no channel is connected to, after a dry run', function () {
    $connected = SocialAccount::factory()->facebook()->create(['workspace_id' => $this->workspace->id]);
    $kept = insightsHistory($connected);
    $gone = SocialAccount::factory()->facebook()->create(['workspace_id' => $this->workspace->id]);
    $left = insightsHistory($gone);
    $gone->delete();

    $this->artisan('socialhq:insights:prune', ['--dry-run' => true])->assertSuccessful();

    expect(insightsHistoryExists($left))->toBeTrue();

    $this->artisan('socialhq:insights:prune')->assertSuccessful();

    expect(insightsHistoryGone($left))->toBeTrue()
        ->and(insightsHistoryExists($kept))->toBeTrue();
});
