<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Middleware;

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
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
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Uri;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

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
 * confirmation page is never reached.
 */
class ActAsConnectInviter
{
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
            return $this->toGuestPage();
        }

        $inviter = $invite->creator;
        $workspace = $invite->workspace;

        if ($inviter === null || $workspace === null) {
            ConnectInviteFlow::forget();

            return $this->toGuestPage();
        }

        $before = $step === self::FINISH ? $this->accountStamps($workspace) : collect();

        // In memory only: the admin's own current workspace is not changed.
        $inviter->setRelation('currentWorkspace', $workspace);
        Auth::guard()->setUser($inviter);

        try {
            $response = $next($request);
        } finally {
            Auth::guard()->forgetUser();
        }

        return match ($step) {
            self::START => $this->afterStart($invite, $response),
            self::FINISH => $this->afterFinish($request, $invite, $workspace, $before, $response),
            default => $this->toGuestPage(),
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
     * The start route answers with a redirect to the network once it has stored
     * the pending connection for the link's workspace. Anything else is a refusal.
     */
    private function afterStart(ConnectInvite $invite, Response $response): Response
    {
        $leaves = $response->isRedirection() || $response->headers->has('X-Inertia-Location');

        if ($leaves && ConnectInviteFlow::pending($invite) !== null) {
            return $response;
        }

        $this->fail($invite);

        return $this->toGuestPage();
    }

    /**
     * "Finish connection" forgets the pending connection only when it stored
     * the channels.
     *
     * @param  Collection<string, string>  $before
     */
    private function afterFinish(Request $request, ConnectInvite $invite, Workspace $workspace, Collection $before, Response $response): Response
    {
        if (! $response->isRedirection() || PendingConnection::current() !== null) {
            return $this->toGuestPage();
        }

        $accounts = $this->connectedAccounts($workspace, $before, $response);

        ConnectInviteFlow::complete($invite, $accounts, $request->ip());

        if ($invite->creator !== null) {
            $accounts->each(fn (SocialAccount $account) => rescue(fn () => Notices::channelConnected($invite->creator, $account)));
        }

        return $this->toGuestPage();
    }

    /**
     * The channels the finish step created or refreshed: rows that are new or
     * changed since it started, and the channel TryPost redirected to.
     *
     * @param  Collection<string, string>  $before
     * @return Collection<int, SocialAccount>
     */
    private function connectedAccounts(Workspace $workspace, Collection $before, Response $response): Collection
    {
        $landed = $this->redirectedChannelId($response);

        return $workspace->socialAccounts()->get()
            ->filter(fn (SocialAccount $account): bool => $account->id === $landed
                || $before->get($account->id) !== (string) $account->updated_at)
            ->values();
    }

    private function redirectedChannelId(Response $response): ?string
    {
        if (! $response instanceof RedirectResponse) {
            return null;
        }

        try {
            $route = Route::getRoutes()->match(Request::create(Uri::of($response->getTargetUrl())->path() ?? '/', 'GET'));
        } catch (Throwable) {
            return null;
        }

        $account = $route->getName() === 'app.channels.publish' ? $route->parameter('account') : null;

        return is_string($account) ? $account : null;
    }

    /**
     * @return Collection<string, string>
     */
    private function accountStamps(Workspace $workspace): Collection
    {
        return $workspace->socialAccounts()->get()
            ->mapWithKeys(fn (SocialAccount $account): array => [$account->id => (string) $account->updated_at]);
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

    private function toGuestPage(): RedirectResponse
    {
        return redirect()->route('socialhq.connect-invite.show');
    }
}
