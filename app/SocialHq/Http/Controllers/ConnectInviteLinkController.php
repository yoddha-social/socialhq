<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers;

use App\Http\Controllers\App\Concerns\EnsuresChannelInCurrentWorkspace;
use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\SocialHq\Channels\ConnectInvite;
use App\SocialHq\Http\Requests\StoreConnectInviteRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * An admin makes a connect link for a channel's network: "Ask the account owner
 * to connect" on the channel page.
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
}
