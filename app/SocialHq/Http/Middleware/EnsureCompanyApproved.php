<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Middleware;

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Companies\CompanyAccess;
use App\SocialHq\Companies\CompanyStatus;
use App\SocialHq\Companies\PlatformAdmins;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only approved companies work (self-hosted). Wherever they go:
 * - the owner of a pending or declined company sees the waiting page;
 * - everyone in a suspended company sees the paused page;
 * - someone with no workspace who can't create one (an invited person's
 *   empty account, a member removed from every workspace) is shown what to
 *   do instead of the workspace form.
 *
 * They can still confirm their email, open and answer invites, and log
 * out. Platform admins always reach the platform pages, whatever their own
 * company's status.
 */
class EnsureCompanyApproved
{
    private const ALLOWED_ROUTES = [
        'socialhq.company.*',
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

        if (! $user instanceof User || ! config('trypost.self_hosted') || $request->routeIs(...self::ALLOWED_ROUTES)) {
            return $next($request);
        }

        if ($request->routeIs('socialhq.platform.*') && PlatformAdmins::is($user)) {
            return $next($request);
        }

        $company = CompanyAccess::companyOf($user);

        $redirect = match (true) {
            $company?->status === CompanyStatus::Suspended => 'socialhq.company.paused',
            $company !== null && $company->isWaiting() => 'socialhq.company.waiting',
            $request->routeIs('app.workspaces.create') && config('socialhq.companies.required', true) && Gate::forUser($user)->denies('create', Workspace::class) => 'socialhq.company.start',
            default => null,
        };

        if ($redirect === null) {
            return $next($request);
        }

        // A message on its way (an invite declined) reaches the page.
        if ($request->hasSession()) {
            $request->session()->reflash();
        }

        return redirect()->route($redirect);
    }
}
