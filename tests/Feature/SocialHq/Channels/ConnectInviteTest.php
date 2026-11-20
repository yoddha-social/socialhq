<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Http\Middleware\App\CaptureOffer;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Channels\ConnectInvite;
use App\SocialHq\Channels\ConnectInviteFlow;
use App\SocialHq\Http\Middleware\ActAsConnectInviter;
use App\SocialHq\Notices\Notice;
use App\SocialHq\Notices\NoticeCreated;
use App\SocialHq\Notices\NoticeKind;
use App\Support\Social\PendingConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Support\SessionKey;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

const CONNECT_INVITE_X_URL = 'https://twitter.com/i/oauth2/authorize?test=1';
const CONNECT_INVITE_TOKEN = 'guest-access-token-never-shown';

beforeEach(function () {
    Mail::fake();
    Event::fake([NoticeCreated::class]);

    $this->admin = User::factory()->create(['name' => 'Asha Gurung']);
    $this->workspace = Workspace::factory()->create(['name' => 'Brew Co', 'user_id' => $this->admin->id, 'account_id' => $this->admin->account_id]);
    $this->workspace->members()->attach($this->admin->id, membershipPivot('admin'));
    $this->admin->update(['current_workspace_id' => $this->workspace->id]);
    subscribeAccount($this->admin->account);

    Http::fake([
        config('trypost.platforms.x.api').'/*' => Http::response(['data' => ['id' => '123456789']]),
    ]);

    $this->channel = SocialAccount::factory()->x()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'existing-1',
        'username' => 'brewco',
    ]);

});

/**
 * One Socialite driver for the whole flow: the redirect to X, then the account
 * X hands back.
 */
function connectInviteFakeX(string $platformUserId = '123456789'): void
{
    $owner = Mockery::mock(SocialiteUser::class);
    $owner->shouldReceive('getId')->andReturn($platformUserId);
    $owner->shouldReceive('getNickname')->andReturn('owner_handle');
    $owner->shouldReceive('getName')->andReturn('Owner Account');
    $owner->shouldReceive('getAvatar')->andReturn(null);
    $owner->token = CONNECT_INVITE_TOKEN;
    $owner->refreshToken = 'guest-refresh-token';
    $owner->expiresIn = 7200;
    $owner->approvedScopes = ['tweet.read', 'tweet.write', 'users.read'];

    $driver = Mockery::mock();
    $driver->shouldReceive('scopes')->andReturnSelf();
    $driver->shouldReceive('with')->andReturnSelf();
    $driver->shouldReceive('redirect')->andReturn(Mockery::mock(['getTargetUrl' => CONNECT_INVITE_X_URL]));
    $driver->shouldReceive('user')->andReturn($owner);

    Socialite::shouldReceive('driver')->with('x')->andReturn($driver);
}

/**
 * A guest opens the link, continues to the network and comes back.
 */
function connectInviteWalkToConfirmation(mixed $test, string $token): void
{
    $test->post(route('socialhq.connect-invite.start', $token))->assertRedirect(route('app.social.x.connect'));
    $test->get(route('app.social.x.connect'))->assertRedirect(CONNECT_INVITE_X_URL);
    $test->get(route('app.social.x.callback'))->assertRedirect(route('socialhq.connect-invite.show'));
}

test('an admin makes a link for a channel network, kept only as a hash', function () {
    $response = $this->actingAs($this->admin)
        ->postJson(route('socialhq.channels.connect-invites.store', $this->channel))
        ->assertCreated();

    $invite = ConnectInvite::query()->sole();
    $token = str($response->json('url'))->afterLast('/')->value();

    expect($response->json('url'))->toBe(route('socialhq.connect-invite.landing', $token))
        ->and($invite->token_hash)->toBe(ConnectInvite::hash($token))
        ->and($invite->token_hash)->not->toBe($token)
        ->and($invite->workspace_id)->toBe($this->workspace->id)
        ->and($invite->created_by)->toBe($this->admin->id)
        ->and($invite->platform)->toBe(Platform::X)
        ->and($invite->reconnect_account_id)->toBeNull()
        ->and((int) round(now()->diffInMinutes($invite->expires_at)))->toBe(ConnectInvite::TTL_MINUTES);
});

test('a link for a channel that lost its connection reconnects that channel only', function () {
    $this->channel->update(['status' => Status::Disconnected]);

    $this->actingAs($this->admin)
        ->postJson(route('socialhq.channels.connect-invites.store', $this->channel))
        ->assertCreated();

    expect(ConnectInvite::query()->sole()->reconnect_account_id)->toBe($this->channel->id);
});

test('only an admin of the channel workspace can make a link, and only for networks that sign in by redirect', function () {
    $member = User::factory()->create(['account_id' => $this->admin->account_id, 'current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($member->id, membershipPivot('member'));
    $foreign = SocialAccount::factory()->x()->create();
    $bluesky = SocialAccount::factory()->bluesky()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($member)->postJson(route('socialhq.channels.connect-invites.store', $this->channel))->assertForbidden();
    $this->actingAs($this->admin)->postJson(route('socialhq.channels.connect-invites.store', $foreign))->assertNotFound();
    $this->actingAs($this->admin)->postJson(route('socialhq.channels.connect-invites.store', $bluesky))->assertUnprocessable();

    expect(ConnectInvite::query()->count())->toBe(0);
});

test('the link says who asks and for what, and starts nothing by being opened', function () {
    [$invite, $token] = ConnectInvite::issue($this->workspace, Platform::X, $this->admin);

    $this->get(route('socialhq.connect-invite.landing', $token))
        ->assertOk()
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertInertia(fn ($page) => $page
            ->component('socialhq/ConnectInvite', false)
            ->where('state', 'intro')
            ->where('invite.workspace', 'Brew Co')
            ->where('invite.inviter', 'Asha Gurung')
            ->where('invite.network', Platform::X->label())
            ->where('invite.channel', Platform::X->label())
            ->where('startUrl', route('socialhq.connect-invite.start', $token))
            ->where('auth.user', null));

    expect($invite->fresh()->opened_at)->not->toBeNull()
        ->and($invite->fresh()->started_at)->toBeNull()
        ->and(ConnectInviteFlow::isActive())->toBeFalse();
    $this->assertGuest();
});

test('the owner connects their account without a Social HQ login, and the link stops working', function () {
    connectInviteFakeX();
    [$invite, $token] = ConnectInvite::issue($this->workspace, Platform::X, $this->admin);

    connectInviteWalkToConfirmation($this, $token);

    expect(PendingConnection::current()?->workspaceId())->toBe($this->workspace->id);
    $this->assertGuest();
    $this->assertDatabaseCount('social_accounts', 1);

    // TryPost's own confirmation page is never shown to the guest.
    $this->get(route('app.social.connect.show', Platform::X))->assertRedirect(route('socialhq.connect-invite.show'));

    $page = $this->get(route('socialhq.connect-invite.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('state', 'select')
            ->where('identities.0.key', 'x:123456789')
            ->where('identities.0.locked', false)
            ->where('finishUrl', route('app.social.connect.finish', Platform::X))
            ->where('auth.user', null));

    expect($page->getContent())->not->toContain(CONNECT_INVITE_TOKEN)
        ->not->toContain('guest-refresh-token')
        ->not->toContain($this->admin->email);

    $this->post(route('app.social.connect.finish', Platform::X), ['identities' => ['x:123456789']])
        ->assertRedirect(route('socialhq.connect-invite.show'));

    $connected = $this->workspace->socialAccounts()->where('platform_user_id', '123456789')->sole();
    $invite->refresh();

    expect($connected->platform)->toBe(Platform::X)
        ->and($connected->status)->toBe(Status::Connected)
        ->and($invite->used_at)->not->toBeNull()
        ->and(data_get($invite->connected, '0.id'))->toBe($connected->id)
        ->and(ConnectInviteFlow::isActive())->toBeFalse()
        ->and(PendingConnection::current())->toBeNull()
        ->and(session()->has(SessionKey::FLASH_DATA))->toBeFalse();
    $this->assertGuest();

    $notice = Notice::query()->where('user_id', $this->admin->id)->sole();
    expect($notice->kind)->toBe(NoticeKind::ChannelConnected)
        ->and($notice->social_account_id)->toBe($connected->id);

    $this->get(route('socialhq.connect-invite.show'))
        ->assertInertia(fn ($page) => $page
            ->where('state', 'done')
            ->where('done.workspace', 'Brew Co')
            ->where('done.channels.0', 'Owner Account'));

    // Used once: the link, and the guest's session, open nothing more.
    $this->get(route('socialhq.connect-invite.landing', $token))->assertInertia(fn ($page) => $page->where('state', 'expired'));
    $this->get(route('socialhq.connect-invite.show'))->assertInertia(fn ($page) => $page->where('state', 'expired'));
    $this->get(route('app.social.x.callback'))->assertRedirect(route('login'));
    $this->post(route('socialhq.connect-invite.start', $token))->assertRedirect(route('socialhq.connect-invite.landing', $token));
    expect(ConnectInviteFlow::isActive())->toBeFalse();
});

test('the channel lands in the link workspace, whatever workspace the admin is in now', function () {
    connectInviteFakeX();
    $elsewhere = Workspace::factory()->create(['user_id' => $this->admin->id, 'account_id' => $this->admin->account_id]);
    $elsewhere->members()->attach($this->admin->id, membershipPivot('admin'));
    [, $token] = ConnectInvite::issue($this->workspace, Platform::X, $this->admin);
    $this->admin->update(['current_workspace_id' => $elsewhere->id]);

    connectInviteWalkToConfirmation($this, $token);
    $this->post(route('app.social.connect.finish', Platform::X), ['identities' => ['x:123456789']])
        ->assertRedirect(route('socialhq.connect-invite.show'));

    expect($this->workspace->socialAccounts()->where('platform_user_id', '123456789')->exists())->toBeTrue()
        ->and($elsewhere->socialAccounts()->count())->toBe(0)
        ->and($this->admin->fresh()->current_workspace_id)->toBe($elsewhere->id);
});

test('a reconnect link refreshes the lost channel and offers nothing else', function () {
    connectInviteFakeX('existing-1');
    $this->channel->forceFill(['status' => Status::Disconnected, 'shq_name' => 'Team name for X'])->save();
    [$invite, $token] = ConnectInvite::issue($this->workspace, Platform::X, $this->admin, $this->channel);

    $this->post(route('socialhq.connect-invite.start', $token))
        ->assertRedirect(route('app.social.x.connect', ['reconnect' => $this->channel->id]));
    $this->get(route('app.social.x.connect', ['reconnect' => $this->channel->id]))->assertRedirect(CONNECT_INVITE_X_URL);
    $this->get(route('app.social.x.callback'))->assertRedirect(route('socialhq.connect-invite.show'));
    $this->post(route('app.social.connect.finish', Platform::X), ['identities' => ['x:existing-1']])
        ->assertRedirect(route('socialhq.connect-invite.show'));

    expect($this->workspace->socialAccounts()->count())->toBe(1)
        ->and($this->channel->fresh()->status)->toBe(Status::Connected)
        ->and($this->channel->fresh()->access_token)->toBe(CONNECT_INVITE_TOKEN)
        ->and(data_get($invite->fresh()->connected, '0.id'))->toBe($this->channel->id)
        ->and(data_get($invite->fresh()->connected, '0.name'))->toBe('Owner Account');

    // The guest sees the network's name for the channel, never the team's own.
    $this->get(route('socialhq.connect-invite.show'))
        ->assertInertia(fn ($page) => $page->where('state', 'done')->where('done.channels', ['Owner Account']));
});

test('a reconnect link stays a reconnect, whatever the address asks for', function () {
    connectInviteFakeX('someone-else');
    $this->channel->update(['status' => Status::Disconnected]);
    [$invite, $token] = ConnectInvite::issue($this->workspace, Platform::X, $this->admin, $this->channel);

    $this->post(route('socialhq.connect-invite.start', $token));
    $this->get(route('app.social.x.connect', ['return_to' => route('app.calendar', absolute: false), 'switch' => '1']))
        ->assertRedirect(CONNECT_INVITE_X_URL);

    $pending = PendingConnection::current();
    expect($pending?->reconnectId())->toBe($this->channel->id)
        ->and($pending?->isSwitchingAccount())->toBeFalse()
        ->and($pending?->returnRouteName())->toBe('app.workspace.channels');

    // The network signs in another account: a reconnect offers nothing to pick.
    $this->get(route('app.social.x.callback'))->assertRedirect(route('socialhq.connect-invite.show'));
    $this->post(route('app.social.connect.finish', Platform::X), ['identities' => ['x:someone-else']])
        ->assertRedirect(route('socialhq.connect-invite.show'));

    expect($this->workspace->socialAccounts()->count())->toBe(1)
        ->and($invite->fresh()->used_at)->toBeNull();
});

test('finish runs only on the connection the link started, once the network offered its accounts', function () {
    connectInviteFakeX();
    [$invite, $token] = ConnectInvite::issue($this->workspace, Platform::X, $this->admin);
    $finish = fn () => $this->withHeader('Referer', route('app.channels.publish', $this->channel))
        ->post(route('app.social.connect.finish', Platform::X), ['identities' => ['x:123456789']]);

    // Pressed Continue, never went to the network.
    $this->post(route('socialhq.connect-invite.start', $token));
    $finish()->assertRedirect(route('socialhq.connect-invite.show'));

    // Went to the network, never came back.
    $this->get(route('app.social.x.connect'))->assertRedirect(CONNECT_INVITE_X_URL);
    $finish()->assertRedirect(route('socialhq.connect-invite.show'));

    expect($invite->fresh()->used_at)->toBeNull()
        ->and($invite->fresh()->connected)->toBeNull()
        ->and($this->workspace->socialAccounts()->count())->toBe(1)
        ->and(Notice::query()->count())->toBe(0);
});

test('a finish while another one runs for the same link changes nothing', function () {
    connectInviteFakeX();
    [$invite, $token] = ConnectInvite::issue($this->workspace, Platform::X, $this->admin);
    connectInviteWalkToConfirmation($this, $token);

    $running = Cache::lock("shq_connect_invite:{$invite->id}", 60);
    $running->get();

    $this->post(route('app.social.connect.finish', Platform::X), ['identities' => ['x:123456789']])
        ->assertRedirect(route('socialhq.connect-invite.show'));

    expect($this->workspace->socialAccounts()->count())->toBe(1)
        ->and($invite->fresh()->used_at)->toBeNull();

    $running->release();
    $this->post(route('app.social.connect.finish', Platform::X), ['identities' => ['x:123456789']]);

    expect($this->workspace->socialAccounts()->count())->toBe(2)
        ->and($invite->fresh()->used_at)->not->toBeNull();
});

test('the steps run as the admin leave the guest offer waiting and are limited per address', function () {
    connectInviteFakeX();
    [, $token] = ConnectInvite::issue($this->workspace, Platform::X, $this->admin);

    $this->withSession([CaptureOffer::SESSION_KEY => 'black-friday-2026'])
        ->post(route('socialhq.connect-invite.start', $token));
    $this->get(route('app.social.x.connect'))->assertRedirect(CONNECT_INVITE_X_URL);

    // TryPost would claim a waiting offer for the admin's account.
    expect(session(CaptureOffer::SESSION_KEY))->toBe('black-friday-2026');

    foreach (range(1, ActAsConnectInviter::STEPS_PER_MINUTE) as $ignored) {
        RateLimiter::hit('socialhq-connect-invite-steps:127.0.0.1');
    }

    PendingConnection::forget();
    $this->get(route('app.social.x.connect'))->assertRedirect(route('socialhq.connect-invite.show'));
    expect(PendingConnection::current())->toBeNull();
});

test('an account the sign-in did not offer cannot be connected, and the link stays open', function () {
    connectInviteFakeX();
    [$invite, $token] = ConnectInvite::issue($this->workspace, Platform::X, $this->admin);

    connectInviteWalkToConfirmation($this, $token);
    $this->post(route('app.social.connect.finish', Platform::X), ['identities' => ['x:someone-else']])
        ->assertRedirect(route('socialhq.connect-invite.show'));

    expect($this->workspace->socialAccounts()->count())->toBe(1)
        ->and($invite->fresh()->used_at)->toBeNull();
    $this->assertGuest();
});

test('a link past its hour opens nothing, even mid-way', function () {
    connectInviteFakeX();
    [$invite, $token] = ConnectInvite::issue($this->workspace, Platform::X, $this->admin);

    connectInviteWalkToConfirmation($this, $token);
    $this->travel(ConnectInvite::TTL_MINUTES + 1)->minutes();

    $this->post(route('app.social.connect.finish', Platform::X), ['identities' => ['x:123456789']])->assertRedirect(route('login'));
    $this->get(route('app.social.x.callback'))->assertRedirect(route('login'));
    $this->get(route('socialhq.connect-invite.landing', $token))->assertInertia(fn ($page) => $page->where('state', 'expired'));

    expect($this->workspace->socialAccounts()->count())->toBe(1)
        ->and($invite->fresh()->used_at)->toBeNull();
});

test('a link gives no way into another network or any other page', function () {
    connectInviteFakeX();
    [, $token] = ConnectInvite::issue($this->workspace, Platform::X, $this->admin);

    $this->post(route('socialhq.connect-invite.start', $token))->assertRedirect(route('app.social.x.connect'));

    $this->get(route('app.social.linkedin.connect'))->assertRedirect(route('login'));
    $this->get(route('app.social.linkedin.callback'))->assertRedirect(route('login'));
    $this->get(route('app.social.connect.show', Platform::LinkedIn))->assertRedirect(route('login'));
    $this->get(route('app.workspace.channels'))->assertRedirect(route('login'));
    $this->get(route('app.channels.settings', $this->channel))->assertRedirect(route('login'));
    $this->delete(route('app.channels.disconnect', $this->channel))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('the link stops working when the admin who sent it no longer manages the workspace', function () {
    connectInviteFakeX();
    $sender = User::factory()->create(['account_id' => $this->admin->account_id, 'current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($sender->id, membershipPivot('admin'));
    [, $token] = ConnectInvite::issue($this->workspace, Platform::X, $sender);
    $this->workspace->members()->updateExistingPivot($sender->id, membershipPivot('member'));

    $this->post(route('socialhq.connect-invite.start', $token))->assertRedirect(route('app.social.x.connect'));
    $this->get(route('app.social.x.connect'))->assertRedirect(route('socialhq.connect-invite.show'));
    $this->get(route('socialhq.connect-invite.show'))->assertInertia(fn ($page) => $page->where('state', 'error'));

    expect(PendingConnection::current())->toBeNull()
        ->and($this->workspace->socialAccounts()->count())->toBe(1);
});

test('someone signed in is told the link is not for them and walks no guest flow', function () {
    $other = User::factory()->create();
    [$invite, $token] = ConnectInvite::issue($this->workspace, Platform::X, $this->admin);

    $this->actingAs($other)
        ->get(route('socialhq.connect-invite.landing', $token))
        ->assertInertia(fn ($page) => $page->where('state', 'signed_in')->missing('invite'));
    $this->actingAs($other)
        ->post(route('socialhq.connect-invite.start', $token))
        ->assertRedirect(route('socialhq.connect-invite.landing', $token));

    expect(ConnectInviteFlow::isActive())->toBeFalse();

    // A flow left in the session from before they signed in is dropped, not used.
    $this->actingAs($other)
        ->withSession([ConnectInviteFlow::SESSION_KEY => $invite->id])
        ->get(route('app.social.connect.show', Platform::X))
        ->assertOk();

    expect(ConnectInviteFlow::isActive())->toBeFalse()
        ->and($invite->fresh()->used_at)->toBeNull();
});

test('a wrong or malformed token shows the same page as an expired link', function () {
    $this->get(route('socialhq.connect-invite.landing', str_repeat('a', 48)))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('state', 'expired')->missing('invite'));
    $this->get('/socialhq/connect-invite/short')->assertNotFound();
});

test('links are deleted some time after they stop working', function () {
    [$old] = ConnectInvite::issue($this->workspace, Platform::X, $this->admin);
    [$recent] = ConnectInvite::issue($this->workspace, Platform::X, $this->admin);
    $old->update(['expires_at' => now()->subDays(ConnectInvite::RETENTION_DAYS + 1)]);

    $this->artisan('model:prune', ['--model' => [ConnectInvite::class]])->assertSuccessful();

    expect(ConnectInvite::query()->pluck('id')->all())->toBe([$recent->id]);
});

test('a link names what is connected and where the owner signs in', function (Platform $platform, string $channel, string $signIn) {
    $invite = new ConnectInvite(['platform' => $platform]);

    expect($invite->channelName())->toBe($channel)
        ->and($invite->signInName())->toBe($signIn);
})->with([
    'a Facebook Page' => [Platform::Facebook, 'Facebook Page', 'Facebook'],
    'Instagram through Facebook' => [Platform::InstagramFacebook, 'Instagram', 'Facebook'],
    'YouTube' => [Platform::YouTube, 'YouTube', 'Google'],
    'LinkedIn' => [Platform::LinkedIn, 'LinkedIn', 'LinkedIn'],
]);
