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
 * still confirm their email and log out.
 */
class RedirectWhileAwaitingAccess
{
    private const ALLOWED_ROUTES = [
        'socialhq.access.pending',
        'logout',
        'verification.notice',
        'verification.verify',
        'verification.send',
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

        return redirect()->route('socialhq.access.pending');
    }
}
