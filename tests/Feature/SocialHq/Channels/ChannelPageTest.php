<?php

declare(strict_types=1);

use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Channels\ChannelPage;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id, 'account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    subscribeAccount($this->user->account);

    $this->channel = SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $this->workspace->id,
        'display_name' => 'Acme Corp',
        'posting_goal' => 3,
    ]);
});

test('the channel page counts where the channel stands', function () {
    $other = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id]);
    $posts = fn () => Post::factory()->forAccount($this->channel)->state(['user_id' => $this->user->id]);

    $posts()->scheduled()->count(2)->create();
    $posts()->scheduled()->create(['scheduled_at' => now()->addDays(ChannelPage::WINDOW_DAYS + 5)]);
    $posts()->pendingApproval()->create();
    $posts()->draft()->count(3)->create();
    $posts()->published()->create();
    $posts()->published()->create(['published_at' => now()->subDays(ChannelPage::WINDOW_DAYS + 5)]);
    Post::factory()->forAccount($other)->scheduled()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user)
        ->get(route('app.channels.settings', $this->channel))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('socialhq/channels/Show', false)
            ->where('stats.scheduled', 2)
            ->where('stats.waiting', 3)
            ->where('stats.pending_approval', 1)
            ->where('stats.drafts', 3)
            ->where('stats.published', 1)
            ->where('week.sent', 1)
            ->where('week.goal', 3)
            ->where('name.custom', null)
            ->where('name.network', 'Acme Corp'));
});

test('an admin names a channel and the name shows wherever the channel does', function () {
    $this->actingAs($this->user)
        ->from(route('app.channels.settings', $this->channel))
        ->put(route('socialhq.channels.name.update', $this->channel), ['name' => '  Main page  '])
        ->assertRedirect(route('app.channels.settings', $this->channel));

    $channel = $this->channel->fresh();

    expect($channel->shq_name)->toBe('Main page')
        ->and($channel->display_label)->toBe('Main page')
        ->and($channel->display_name)->toBe('Acme Corp')
        ->and(data_get($channel->channelSnapshot(), 'platform_name'))->toBe('Main page');

    $this->get(route('app.channels.settings', $this->channel))
        ->assertInertia(fn ($page) => $page
            ->where('channel.display_label', 'Main page')
            ->where('name.custom', 'Main page')
            ->where('name.network', 'Acme Corp'));
});

test('an empty name goes back to the name from the network', function () {
    ChannelPage::rename($this->channel, 'Main page');

    $this->actingAs($this->user)
        ->put(route('socialhq.channels.name.update', $this->channel), ['name' => ''])
        ->assertRedirect();

    expect($this->channel->fresh()->shq_name)->toBeNull()
        ->and($this->channel->fresh()->display_label)->toBe('Acme Corp');
});

test('a name that is too long is refused', function () {
    $this->actingAs($this->user)
        ->put(route('socialhq.channels.name.update', $this->channel), ['name' => str_repeat('a', ChannelPage::NAME_MAX_LENGTH + 1)])
        ->assertSessionHasErrors('name');

    expect($this->channel->fresh()->shq_name)->toBeNull();
});

test('a member who is not an admin cannot name a channel', function () {
    $member = User::factory()->create(['account_id' => $this->user->account_id, 'current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($member->id, membershipPivot('member'));

    $this->actingAs($member)
        ->put(route('socialhq.channels.name.update', $this->channel), ['name' => 'Mine'])
        ->assertForbidden();

    expect($this->channel->fresh()->shq_name)->toBeNull();
});

test('a channel of another workspace cannot be named', function () {
    $foreign = SocialAccount::factory()->linkedin()->create();

    $this->actingAs($this->user)
        ->put(route('socialhq.channels.name.update', $foreign), ['name' => 'Mine'])
        ->assertNotFound();

    expect($foreign->fresh()->shq_name)->toBeNull();
});

test('a channel loaded without the name column still has a label', function () {
    ChannelPage::rename($this->channel, 'Main page');

    $partial = SocialAccount::query()->select(['id', 'platform', 'username', 'display_name'])->findOrFail($this->channel->id);

    expect($partial->display_label)->toBe('Acme Corp');
});

test('disconnecting from the channel page goes to the next channel, then to the channel list', function () {
    $next = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->user)
        ->from(route('app.channels.settings', $this->channel))
        ->delete(route('app.channels.disconnect', $this->channel))
        ->assertRedirect(route('app.channels.settings', $next));

    $this->from(route('app.channels.settings', $next))
        ->delete(route('app.channels.disconnect', $next))
        ->assertRedirect(route('app.workspace.channels'));
});

test('disconnecting from the channel list still goes back to it', function () {
    $this->actingAs($this->user)
        ->from(route('app.workspace.channels'))
        ->delete(route('app.channels.disconnect', $this->channel))
        ->assertRedirect(route('app.workspace.channels'));
});
