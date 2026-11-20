<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\SocialHq\Channels\ConnectInvite;
use App\SocialHq\Channels\ConnectInviteFlow;
use App\Support\Social\PendingConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The page a connect link opens, for the owner of a network account who has no
 * Social HQ login. The sign-in on the network itself is TryPost's connect flow,
 * walked through `ActAsConnectInviter`.
 */
class ConnectInviteController extends Controller
{
    private const string PAGE = 'socialhq/ConnectInvite';

    /** Failures whose TryPost copy would tell a guest about the workspace's internals. */
    private const array GENERIC_FAILURES = ['workspace_not_found', 'session_expired', 'error_connecting'];

    /**
     * The link itself. It only shows what is asked; nothing starts until "Continue".
     */
    public function landing(Request $request, string $token): Response
    {
        $invite = ConnectInvite::findOpenByToken($token);

        if ($invite === null) {
            return $this->page($request, ['state' => 'expired']);
        }

        if ($request->user() !== null) {
            return $this->page($request, ['state' => 'signed_in']);
        }

        if ($invite->opened_at === null) {
            $invite->update(['opened_at' => now()]);
        }

        return $this->page($request, [
            'state' => 'intro',
            'invite' => $this->describe($invite),
            'startUrl' => route('socialhq.connect-invite.start', $token),
        ]);
    }

    public function start(Request $request, string $token): Response
    {
        $invite = $request->user() === null ? ConnectInvite::findOpenByToken($token) : null;

        if ($invite === null) {
            return redirect()->route('socialhq.connect-invite.landing', $token);
        }

        ConnectInviteFlow::begin($invite);

        return $this->toNetwork($invite);
    }

    /**
     * "Try again" after the network refused or the guest cancelled there.
     */
    public function retry(Request $request): Response
    {
        $invite = $request->user() === null ? ConnectInviteFlow::current() : null;

        if ($invite === null) {
            return redirect()->route('socialhq.connect-invite.show');
        }

        ConnectInviteFlow::begin($invite);

        return $this->toNetwork($invite);
    }

    /**
     * Where the guest is in the flow: choosing accounts, finished, or stopped.
     */
    public function show(Request $request): Response
    {
        $done = ConnectInviteFlow::done();

        if ($done !== null) {
            $request->session()->forget(ConnectInviteFlow::DONE_KEY);

            return $this->page($request, ['state' => 'done', 'done' => $done]);
        }

        $invite = $request->user() === null ? ConnectInviteFlow::current() : null;

        if ($invite === null) {
            return $this->page($request, ['state' => 'expired']);
        }

        $pending = ConnectInviteFlow::pending($invite);
        $shared = [
            'invite' => $this->describe($invite),
            'retryUrl' => route('socialhq.connect-invite.retry'),
        ];

        if ($pending?->isReady()) {
            return $this->page($request, [
                ...$shared,
                'state' => 'select',
                'identities' => $this->cards($pending),
                'finishUrl' => route('app.social.connect.finish', $invite->platform),
            ]);
        }

        return $this->page($request, [
            ...$shared,
            'state' => 'error',
            'reason' => $this->reason($pending?->failure()),
        ]);
    }

    private function toNetwork(ConnectInvite $invite): Response
    {
        return Inertia::location(route("app.social.{$invite->networkSlug()}.connect", array_filter([
            'reconnect' => $invite->reconnect_account_id,
        ])));
    }

    /**
     * @return array{workspace: string, platform: string, channel: string, network: string, inviter: ?string, reconnecting: bool}
     */
    private function describe(ConnectInvite $invite): array
    {
        return [
            'workspace' => (string) $invite->workspace?->name,
            'platform' => $invite->platform->value,
            'channel' => $invite->channelName(),
            'network' => $invite->signInName(),
            'inviter' => $invite->creator?->name,
            'reconnecting' => $invite->reconnect_account_id !== null,
        ];
    }

    /**
     * What the page shows of each account the sign-in offered: never its tokens.
     *
     * @return list<array{key: string, platform: string, name: ?string, username: ?string, avatar: ?string, locked: bool}>
     */
    private function cards(PendingConnection $pending): array
    {
        $locked = $pending->lockedIdentityKeys();

        return array_map(fn (array $identity): array => [
            'key' => (string) data_get($identity, 'key'),
            'platform' => (string) data_get($identity, 'platform'),
            'name' => data_get($identity, 'name') ?? data_get($identity, 'username'),
            'username' => data_get($identity, 'username'),
            'avatar' => data_get($identity, 'avatar'),
            'locked' => in_array(data_get($identity, 'key'), $locked, true),
        ], $pending->identities());
    }

    /**
     * Why nothing was connected, as a lang key of ours or TryPost's sentence.
     *
     * @return array{key: ?string, text: ?string}
     */
    private function reason(?string $failure): array
    {
        if ($failure === 'cancelled') {
            return ['key' => 'socialhq.connect_invite.error.cancelled', 'text' => null];
        }

        $key = "accounts.connect.errors.{$failure}";

        if ($failure === null || in_array($failure, self::GENERIC_FAILURES, true) || ! Lang::has($key)) {
            return ['key' => 'socialhq.connect_invite.error.generic', 'text' => null];
        }

        return ['key' => null, 'text' => __($key)];
    }

    /**
     * The link's token is in the address, so the page tells the browser not to
     * pass its address on to the network.
     *
     * @param  array<string, mixed>  $props
     */
    private function page(Request $request, array $props): Response
    {
        $response = Inertia::render(self::PAGE, $props)->toResponse($request);
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
