<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Middleware;

use App\Enums\SocialAccount\Platform;
use App\Http\Middleware\App\CaptureOffer;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Channels\ConnectInvite;
use App\SocialHq\Channels\ConnectInviteFlow;
use App\SocialHq\Notices\Notices;
use App\Support\Social\PendingConnection;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Support\SessionKey;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a guest who opened a connect link walk TryPost's own connect flow.
 *
 * TryPost's start route, OAuth callback and "Finish connection" all need a
 * signed-in admin of the workspace. For a guest whose session holds a link that
 * still works, those three requests (and only those, for the link's network)
 * run with the admin who sent the link as the request's user. Nothing is written
 * to the session, so the guest is never signed in, and the admin's rights are
 * checked again by TryPost on every step.
 *
 * The guest never receives a page rendered for that admin: whatever TryPost
 * answers is replaced by a redirect to the guest's own page, and TryPost's
 * confirmation page is never reached. The guest cannot widen what the link
 * allows: the start route sees only the link's own options, and "Finish
 * connection" runs only on the connection that start stored, once.
 */
class ActAsConnectInviter
{
    /**
     * Steps a guest may run as the admin per minute, from one IP address.
     */
    public const int STEPS_PER_MINUTE = 30;

    private const string START = 'start';

    private const string CALLBACK = 'callback';

    private const string CONFIRM = 'confirm';

    private const string FINISH = 'finish';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! ConnectInviteFlow::isActive()) {
            return $next($request);
        }

        // Someone signed in walks TryPost's flow under their own rights.
        if ($request->user() !== null) {
            ConnectInviteFlow::forget();

            return $next($request);
        }

        $invite = ConnectInviteFlow::current();
        $step = $invite ? $this->step($request, $invite) : null;

        if ($invite === null || $step === null) {
            return $next($request);
        }

        if ($step === self::CONFIRM) {
            return $this->toGuestPage($request);
        }

        $inviter = $invite->creator;
        $workspace = $invite->workspace;

        if ($inviter === null || $workspace === null) {
            ConnectInviteFlow::forget();

            return $this->toGuestPage($request);
        }

        if (! RateLimiter::attempt('socialhq-connect-invite-steps:'.$request->ip(), self::STEPS_PER_MINUTE, fn (): bool => true)) {
            return $this->toGuestPage($request);
        }

        $this->onlyWhatTheLinkAllows($request, $invite, $step);

        return match ($step) {
            self::START => $this->start($request, $next, $invite, $inviter, $workspace),
            self::FINISH => $this->finish($request, $next, $invite, $inviter, $workspace),
            default => $this->callback($request, $next, $inviter, $workspace),
        };
    }

    /**
     * Which step of the link's flow this request is, or null when it is none of them.
     */
    private function step(Request $request, ConnectInvite $invite): ?string
    {
        $name = $request->route()?->getName();
        $slug = $invite->networkSlug();
        $platform = $request->route('platform');
        $platform = $platform instanceof Platform ? $platform : Platform::tryFrom((string) $platform);

        return match (true) {
            $name === "app.social.{$slug}.connect" && $request->isMethod('GET') => self::START,
            $name === "app.social.{$slug}.callback" => self::CALLBACK,
            $name === 'app.social.connect.show' && $platform === $invite->platform => self::CONFIRM,
            $name === 'app.social.connect.finish' && $platform === $invite->platform => self::FINISH,
            default => null,
        };
    }

    /**
     * The start route takes the link's own options and nothing from the address:
     * a reconnect link stays a reconnect, with no `return_to` or `switch`. No step
     * sees an `?offer=`, which TryPost would claim for the admin's account.
     */
    private function onlyWhatTheLinkAllows(Request $request, ConnectInvite $invite, string $step): void
    {
        $request->query->remove('offer');

        if ($step === self::START) {
            $request->query->replace(array_filter(['reconnect' => $invite->reconnect_account_id]));
        }
    }

    /**
     * The start route answers with a redirect to the network once it has stored
     * the pending connection for the link. Anything else is a refusal. The old
     * pending connection is dropped first, so one found afterwards was stored by
     * this request.
     */
    private function start(Request $request, Closure $next, ConnectInvite $invite, User $inviter, Workspace $workspace): Response
    {
        PendingConnection::forget();

        $response = $this->actAs($request, $next, $inviter, $workspace);
        $leaves = $response->isRedirection() || $response->headers->has('X-Inertia-Location');

        if ($leaves && $this->isLinkConnection(ConnectInviteFlow::pending($invite), $invite)) {
            return $response;
        }

        $this->fail($invite);

        return $this->toGuestPage($request);
    }

    private function callback(Request $request, Closure $next, User $inviter, Workspace $workspace): Response
    {
        $this->actAs($request, $next, $inviter, $workspace);

        return $this->toGuestPage($request);
    }

    /**
     * "Finish connection" runs only on the link's own connection once the network
     * has offered its accounts, and one request at a time, so the link is used once.
     */
    private function finish(Request $request, Closure $next, ConnectInvite $invite, User $inviter, Workspace $workspace): Response
    {
        $pending = ConnectInviteFlow::pending($invite);

        if (! $this->isLinkConnection($pending, $invite) || ! $pending->isReady()) {
            return $this->toGuestPage($request);
        }

        $lock = Cache::lock("shq_connect_invite:{$invite->id}", 60);

        if (! $lock->get()) {
            return $this->toGuestPage($request);
        }

        try {
            if (! $invite->fresh()?->isOpen()) {
                ConnectInviteFlow::forget();

                return $this->toGuestPage($request);
            }

            $response = $this->actAs($request, $next, $inviter, $workspace);

            // TryPost forgets the pending connection only once it stored the channels.
            if ($response->isRedirection() && PendingConnection::current() === null) {
                $this->complete($request, $invite, $workspace, $pending);
            }
        } finally {
            $lock->release();
        }

        return $this->toGuestPage($request);
    }

    /**
     * Runs TryPost's step as the admin who sent the link, for this request only.
     * The guest's waiting offer is put aside so TryPost does not claim it for the
     * admin's account.
     */
    private function actAs(Request $request, Closure $next, User $inviter, Workspace $workspace): Response
    {
        $offer = $request->session()->pull(CaptureOffer::SESSION_KEY);

        // In memory only: the admin's own current workspace is not changed.
        $inviter->setRelation('currentWorkspace', $workspace);
        Auth::guard()->setUser($inviter);

        try {
            return $next($request);
        } finally {
            Auth::guard()->forgetUser();

            if ($offer !== null) {
                $request->session()->put(CaptureOffer::SESSION_KEY, $offer);
            }
        }
    }

    /**
     * @phpstan-assert-if-true PendingConnection $pending
     */
    private function isLinkConnection(?PendingConnection $pending, ConnectInvite $invite): bool
    {
        return $pending !== null && $pending->reconnectId() === $invite->reconnect_account_id;
    }

    private function complete(Request $request, ConnectInvite $invite, Workspace $workspace, PendingConnection $pending): void
    {
        $accounts = $this->connectedAccounts($workspace, $pending->selected($this->pickedKeys($request)));

        ConnectInviteFlow::complete($invite, $accounts, $request->ip());

        if ($invite->creator !== null) {
            $accounts->each(fn (SocialAccount $account) => rescue(fn () => Notices::channelConnected($invite->creator, $account)));
        }
    }

    /**
     * @return list<string>
     */
    private function pickedKeys(Request $request): array
    {
        return array_values(array_filter((array) $request->input('identities', []), is_string(...)));
    }

    /**
     * The workspace's channels for the accounts the guest picked: what they
     * connected, and nothing another request changed meanwhile.
     *
     * @param  list<array<string, mixed>>  $identities
     * @return Collection<int, SocialAccount>
     */
    private function connectedAccounts(Workspace $workspace, array $identities): Collection
    {
        $keys = array_column($identities, 'key');

        return $workspace->socialAccounts()
            ->whereIn('platform_user_id', array_column($identities, 'platform_user_id'))
            ->get()
            ->filter(fn (SocialAccount $account): bool => in_array("{$account->platform->value}:{$account->platform_user_id}", $keys, true))
            ->values();
    }

    private function fail(ConnectInvite $invite): void
    {
        $pending = ConnectInviteFlow::pending($invite);

        if ($pending === null) {
            PendingConnection::forget();

            return;
        }

        if ($pending->failure() === null) {
            $pending->fail('error_connecting');
        }
    }

    /**
     * The guest's own page. What TryPost flashed for the admin's next page (the
     * channel it opened) is dropped, so the guest's page never carries it.
     */
    private function toGuestPage(Request $request): RedirectResponse
    {
        $request->session()->forget(SessionKey::FLASH_DATA);

        return redirect()->route('socialhq.connect-invite.show');
    }
}
