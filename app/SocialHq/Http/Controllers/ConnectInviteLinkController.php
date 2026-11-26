<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers;

use App\Http\Controllers\App\Concerns\EnsuresChannelInCurrentWorkspace;
use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\SocialHq\Channels\ConnectInvite;
use App\SocialHq\Http\Requests\DestroyConnectInviteRequest;
use App\SocialHq\Http\Requests\StoreConnectInviteRequest;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * An admin makes a connect link for a channel's network: "Ask the account owner
 * to connect" on the channel page. A link sent to the wrong person can be
 * cancelled from the same page (audit 2026-11-24, SEC-17).
 */
class ConnectInviteLinkController extends Controller
{
    use EnsuresChannelInCurrentWorkspace;

    public function store(StoreConnectInviteRequest $request, SocialAccount $account): JsonResponse
    {
        $this->ensureCurrentWorkspace($request, $account);

        $entry = ConnectInvite::entryFor($account->platform);
        abort_if($entry === null, Response::HTTP_UNPROCESSABLE_ENTITY);

        [$invite, $token] = ConnectInvite::issue(
            $request->user()->currentWorkspace,
            $entry,
            $request->user(),
            $account->isDisconnected() ? $account : null,
        );

        return response()->json([
            'url' => route('socialhq.connect-invite.landing', $token),
            'expires_at' => $invite->expires_at->toIso8601ZuluString(),
        ], Response::HTTP_CREATED);
    }

    /**
     * Cancels a link of the current workspace that still works. It takes the
     * lock the guest's "Finish connection" holds (ActAsConnectInviter), so a
     * link is either cancelled or used, never both: while a guest is finishing,
     * the admin is told to look at the channel list instead.
     */
    public function destroy(DestroyConnectInviteRequest $request, int $invite): RedirectResponse
    {
        $found = ConnectInvite::query()
            ->where('workspace_id', $request->user()->current_workspace_id)
            ->findOrFail($invite);

        try {
            Cache::lock("shq_connect_invite:{$found->id}", 60)->block(5, function () use ($found, $request): void {
                $fresh = $found->fresh();

                if ($fresh === null || ! $fresh->isOpen()) {
                    throw ValidationException::withMessages(['invite' => __('socialhq.channels.invite.not_open')]);
                }

                $fresh->cancel($request->user());
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['invite' => __('socialhq.channels.invite.in_use')]);
        }

        session()->flash('flash.success', __('socialhq.channels.invite.cancelled'));

        return back();
    }
}
