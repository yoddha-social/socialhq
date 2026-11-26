<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Channels\ConnectInvite;
use App\SocialHq\Channels\ConnectInviteFlow;
use App\SocialHq\Notices\NoticeCreated;
use App\Support\Social\PendingConnection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

/*
 * A connect link on Facebook, the network the team uses most (audit
 * 2026-11-24, ARC-05). ConnectInviteTest walks the same flow on X; this one
 * walks TryPost's Facebook connect (Pages from the Graph API, a Page token per
 * channel) as a guest, through ActAsConnectInviter. What the flow relies on in
 * TryPost: docs/socialhq/what-we-rely-on-trypost-for.md.
 */

const CONNECT_INVITE_FACEBOOK_URL = 'https://www.facebook.com/v25.0/dialog/oauth?test=1';

beforeEach(function () {
    Mail::fake();
    Event::fake([NoticeCreated::class]);
    config(['services.facebook.client_id' => 'facebook-app', 'services.facebook.client_secret' => 'facebook-secret']);

    $this->admin = User::factory()->create(['name' => 'Asha Gurung']);
    $this->workspace = Workspace::factory()->create(['name' => 'Brew Co', 'user_id' => $this->admin->id, 'account_id' => $this->admin->account_id]);
    $this->workspace->members()->attach($this->admin->id, membershipPivot('admin'));
    $this->admin->update(['current_workspace_id' => $this->workspace->id]);
    subscribeAccount($this->admin->account);

    $this->channel = SocialAccount::factory()->facebook()->create(['workspace_id' => $this->workspace->id, 'platform_user_id' => 'page_existing']);

    $owner = Mockery::mock(SocialiteUser::class);
    $owner->shouldReceive('getId')->andReturn('facebook_owner_1');
    $owner->token = 'guest-user-token-never-shown';

    $driver = Mockery::mock();
    $driver->shouldReceive('usingGraphVersion')->andReturnSelf();
    $driver->shouldReceive('setScopes')->andReturnSelf();
    $driver->shouldReceive('reRequest')->andReturnSelf();
    $driver->shouldReceive('with')->andReturnSelf();
    $driver->shouldReceive('redirect')->andReturn(Mockery::mock(['getTargetUrl' => CONNECT_INVITE_FACEBOOK_URL]));
    $driver->shouldReceive('user')->andReturn($owner);
    Socialite::shouldReceive('driver')->with('facebook')->andReturn($driver);

    $graphApi = config('trypost.platforms.facebook.graph_api');
    Http::fake([
        "{$graphApi}/me/permissions*" => Http::response(['data' => [['permission' => 'pages_show_list', 'status' => 'granted']]]),
        "{$graphApi}/me?*" => Http::response(['id' => 'facebook_owner_1', 'name' => 'Page Owner']),
        "{$graphApi}/me/businesses*" => Http::response(['data' => []]),
        "{$graphApi}/me/accounts*" => Http::response(['data' => [
            ['id' => 'page_cafe', 'name' => 'Brew Co Cafe', 'username' => 'brewcocafe', 'picture' => ['data' => ['url' => null]], 'access_token' => 'page-token-never-shown'],
            ['id' => 'page_other', 'name' => 'Another Page', 'username' => 'another', 'picture' => ['data' => ['url' => null]], 'access_token' => 'other-token-never-shown'],
        ]]),
        "{$graphApi}/*" => Http::response(['id' => 'page_cafe', 'name' => 'Brew Co Cafe']),
    ]);
});

test('a Facebook Page owner connects their Page through a link, without a Social HQ login', function () {
    [$invite, $token] = ConnectInvite::issue($this->workspace, ConnectInvite::entryFor($this->channel->platform), $this->admin);

    expect($invite->platform)->toBe(Platform::Facebook)
        ->and($invite->signInName())->toBe('Facebook');

    $this->post(route('socialhq.connect-invite.start', $token))->assertRedirect(route('app.social.facebook.connect'));
    $this->get(route('app.social.facebook.connect'))->assertRedirect(CONNECT_INVITE_FACEBOOK_URL);
    $this->get(route('app.social.facebook.callback'))->assertRedirect(route('socialhq.connect-invite.show'));

    expect(PendingConnection::current()?->workspaceId())->toBe($this->workspace->id);
    $this->assertGuest();

    // TryPost's own confirmation page is never shown to the guest.
    $this->get(route('app.social.connect.show', Platform::Facebook))->assertRedirect(route('socialhq.connect-invite.show'));

    $page = $this->get(route('socialhq.connect-invite.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('state', 'select')
            ->where('identities.0.key', 'facebook:page_cafe')
            ->where('identities.1.key', 'facebook:page_other')
            ->where('finishUrl', route('app.social.connect.finish', Platform::Facebook))
            ->where('auth.user', null));

    expect($page->getContent())->not->toContain('page-token-never-shown')
        ->not->toContain('guest-user-token-never-shown')
        ->not->toContain($this->admin->email);

    $this->post(route('app.social.connect.finish', Platform::Facebook), ['identities' => ['facebook:page_cafe']])
        ->assertRedirect(route('socialhq.connect-invite.show'));

    $connected = $this->workspace->socialAccounts()->where('platform_user_id', 'page_cafe')->sole();

    expect($connected->platform)->toBe(Platform::Facebook)
        ->and($connected->status)->toBe(Status::Connected)
        ->and($connected->access_token)->toBe('page-token-never-shown')
        ->and($this->workspace->socialAccounts()->where('platform_user_id', 'page_other')->exists())->toBeFalse()
        ->and($invite->fresh()->used_at)->not->toBeNull()
        ->and(data_get($invite->fresh()->connected, '0.id'))->toBe($connected->id)
        ->and(ConnectInviteFlow::isActive())->toBeFalse();
    $this->assertGuest();

    // Used once: the link opens nothing more.
    $this->get(route('socialhq.connect-invite.landing', $token))->assertInertia(fn ($page) => $page->where('state', 'expired'));
    $this->get(route('app.social.facebook.callback'))->assertRedirect(route('login'));
});
