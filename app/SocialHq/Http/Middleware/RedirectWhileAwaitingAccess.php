<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Middleware;

use App\Models\User;
use App\SocialHq\Signup\AccessRequests;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * People who signed up and are not let in yet see the waiting page, wherever
 * they go (welcome steps, workspace creation, the calendar...). They can
 * still confirm their email, open and answer an invite, and log out.
 * Accepting an invite gives them a workspace, which closes their request
 * (AccessRequests::closeOnceJoined).
 */
class RedirectWhileAwaitingAccess
{
    private const ALLOWED_ROUTES = [
        'socialhq.access.pending',
        'logout',
        'verification.notice',
        'verification.verify',
        'verification.send',
        'app.invites.show',
        'app.invites.accept',
        'app.invites.decline',
    ];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User
            || $user->current_workspace_id !== null
            || $request->routeIs(...self::ALLOWED_ROUTES)
            || AccessRequests::pendingFor($user) === null) {
            return $next($request);
        }

        // A message on its way (an invite declined) reaches the waiting page.
        if ($request->hasSession()) {
            $request->session()->reflash();
        }

        return redirect()->route('socialhq.access.pending');
    }
}
