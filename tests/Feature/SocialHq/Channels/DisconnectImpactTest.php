<?php

declare(strict_types=1);

use App\Enums\Repurpose\SourceFormat;
use App\Enums\SocialAccount\Platform;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsSyncState;
use App\Models\Media;
use App\Models\Post;
use App\Models\Repurpose;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Channels\DisconnectImpact;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\ItemStatus;
use App\SocialHq\Plan\PlanItem;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    Mail::fake();

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id, 'account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    subscribeAccount($this->user->account);

    $this->channel = SocialAccount::factory()->facebook()->create([
        'workspace_id' => $this->workspace->id,
        'posting_goal' => 4,
    ]);
});

test('the disconnect dialog counts every post the disconnect deletes, by status', function () {
    $posts = fn () => Post::factory()->forAccount($this->channel)->state(['user_id' => $this->user->id]);
    $posts()->scheduled()->count(2)->create();
    $posts()->scheduled()->create(['scheduled_at' => now()->addMonths(3)]);
    $posts()->pendingApproval()->create();
    $posts()->draft()->count(2)->create();
    $posts()->failed()->create();
    $sent = $posts()->published()->count(4)->create();
    $sent->each(fn (Post $post) => Media::factory()->ownedByPost($post)->create());
    Post::factory()->forAccount(SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id]))
        ->scheduled()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user)
        ->getJson(route('socialhq.channels.disconnect-impact.show', $this->channel))
        ->assertOk()
        ->assertJsonPath('posts', ['scheduled' => 3, 'pending_approval' => 1, 'drafts' => 2, 'failed' => 1, 'sent' => 4])
        ->assertJsonPath('unsent', 7)
        ->assertJsonPath('media', 4)
        ->assertJsonPath('posting_goal', 4)
        ->assertJsonPath('network', 'Facebook');
});

test('it lists the next scheduled posts as plain text, soonest first', function () {
    $posts = fn () => Post::factory()->forAccount($this->channel)->scheduled()->state(['user_id' => $this->user->id]);
    $posts()->create(['scheduled_at' => now()->addDays(5), 'content' => '<p>Third</p>']);
    $soonest = $posts()->create(['scheduled_at' => now()->addDay(), 'content' => '<p>First <strong>post</strong></p>']);
    $posts()->create(['scheduled_at' => now()->addDays(2), 'content' => '<p>Second</p>']);
    $posts()->create(['scheduled_at' => now()->addDays(9), 'content' => '<p>Fourth</p>']);

    $response = $this->actingAs($this->user)
        ->getJson(route('socialhq.channels.disconnect-impact.show', $this->channel))
        ->assertOk();

    expect($response->json('upcoming'))->toHaveCount(DisconnectImpact::UPCOMING)
        ->and($response->json('upcoming.*.excerpt'))->toBe(['First post', 'Second', 'Third'])
        ->and($response->json('upcoming.0.id'))->toBe($soonest->id);
});

test('it says what else stops: the queue, planned items and repurposes that read from the channel', function () {
    $plan = ContentPlan::factory()->create(['workspace_id' => $this->workspace->id]);
    $item = fn (array $attributes = []) => PlanItem::factory()->create([
        'plan_id' => $plan->id,
        'social_account_id' => $this->channel->id,
        'scheduled_on' => now()->addDays(3)->toDateString(),
        ...$attributes,
    ]);
    $item();
    $item();
    $item(['status' => ItemStatus::Dropped]);
    $item(['post_created_at' => now()]);
    $item(['scheduled_on' => now()->subDays(3)->toDateString()]);

    $repurpose = fn () => Repurpose::factory()->state([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'source_social_account_id' => $this->channel->id,
    ]);
    // One repurpose per source format of a channel.
    $repurpose()->active()->create(['source_format' => SourceFormat::Reel]);
    $repurpose()->paused()->create(['source_format' => SourceFormat::Video]);
    $repurpose()->disabled()->create(['source_format' => SourceFormat::Story]);

    $this->actingAs($this->user)
        ->getJson(route('socialhq.channels.disconnect-impact.show', $this->channel))
        ->assertOk()
        ->assertJsonPath('plan_items', 2)
        ->assertJsonPath('repurposes', 2)
        ->assertJsonPath('posting_times', $this->channel->posting_schedule?->slotCount() ?? 0);
});

test('it counts the Insights history the disconnect deletes, none while another channel is the same account', function () {
    $identity = AnalyticsSyncState::providerIdentityFor($this->channel);
    AnalyticsPublication::factory()->count(3)->create([...$identity, 'social_account_id' => $this->channel->id, 'platform' => $this->channel->platform]);
    AnalyticsPublication::factory()->create([...$identity, 'social_account_key' => (string) Str::uuid(), 'platform' => $this->channel->platform]);
    $impact = fn (SocialAccount $channel): mixed => $this->actingAs($this->user)
        ->getJson(route('socialhq.channels.disconnect-impact.show', $channel))
        ->assertOk()
        ->json('insights');

    $followersOnly = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id]);
    AnalyticsAccountDailySnapshot::factory()->create([
        ...AnalyticsSyncState::providerIdentityFor($followersOnly),
        'social_account_id' => $followersOnly->id,
        'platform' => $followersOnly->platform,
    ]);

    expect($impact($this->channel))->toBe(4)
        ->and($impact($followersOnly))->toBe(0)
        ->and($impact(SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id])))->toBeNull();

    $sameAccount = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::InstagramFacebook,
        'platform_user_id' => $sameAccount->platform_user_id,
    ]);
    AnalyticsPublication::factory()->create([...AnalyticsSyncState::providerIdentityFor($sameAccount), 'platform' => $sameAccount->platform]);

    expect($impact($sameAccount))->toBeNull();
});

test('only admins of the channel workspace see what a disconnect deletes', function () {
    $member = User::factory()->create(['account_id' => $this->user->account_id, 'current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($member->id, membershipPivot('member'));
    $foreign = SocialAccount::factory()->x()->create();

    $this->actingAs($member)->getJson(route('socialhq.channels.disconnect-impact.show', $this->channel))->assertForbidden();
    $this->actingAs($this->user)->getJson(route('socialhq.channels.disconnect-impact.show', $foreign))->assertNotFound();
});

test('the network is named as people call it', function (Platform $platform, string $name) {
    expect(DisconnectImpact::networkName($platform))->toBe($name);
})->with([
    'Facebook Page' => [Platform::Facebook, 'Facebook'],
    'Instagram through Facebook' => [Platform::InstagramFacebook, 'Instagram'],
    'LinkedIn Page' => [Platform::LinkedInPage, 'LinkedIn'],
    'YouTube' => [Platform::YouTube, 'YouTube'],
    'X' => [Platform::X, 'X'],
]);
