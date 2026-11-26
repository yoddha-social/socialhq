<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Invite\AcceptInvite;
use App\Actions\Invite\DeclineInvite;
use App\Actions\Invite\ResolveInviteWorkspaces;
use App\Actions\Invite\SettleAfterInvite;
use App\Enums\Invite\Result;
use App\Http\Controllers\Controller;
use App\Models\Invite;
use App\SocialHq\Access\Role;
use App\SocialHq\Access\RoleResource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AcceptInviteController extends Controller
{
    /**
     * Display the invite view.
     */
    public function show(Request $request, Invite $invite): Response
    {
        $user = $request->user();
        $workspaces = ResolveInviteWorkspaces::execute($invite);
        $expired = $workspaces->isEmpty();

        if ($expired) {
            // Non-mutating for crawler/email prefetch: cleanup happens on
            // workspace delete and on accept/decline of a dead invite.
            return Inertia::render('auth/AcceptInvite', [
                'expired' => true,
                'invite' => null,
            ]);
        }

        $workspace = $workspaces->first();

        return Inertia::render('auth/AcceptInvite', [
            'expired' => false,
            'invite' => [
                'id' => $invite->id,
                'email' => $invite->email,
                'workspace' => [
                    'id' => $workspace->id,
                    'name' => $workspace->name,
                ],
                'access' => [
                    'is_admin' => $invite->is_admin,
                    'requires_approval' => $invite->requires_approval,
                ],
                // Social HQ: the role it joins with (null for an invite from before roles).
                'role' => RoleResource::summary(Role::query()->find($invite->shq_role_id)),
            ],
            // Social HQ: only the invited person, signed in, learns their account can't move.
            'otherAccount' => $user !== null && $invite->isFor($user->email) && AcceptInvite::holdsOtherAccount($user, $invite),
        ]);
    }

    /**
     * Accept the invite.
     */
    public function accept(Request $request, Invite $invite): RedirectResponse
    {
        $result = AcceptInvite::execute($request->user(), $invite);

        // Social HQ: nothing changed for someone who stays in their own company.
        if ($result === Result::OtherAccount) {
            return redirect()->route('app.invites.show', $invite)
                ->with('flash.banner', $result->flashBanner())
                ->with('flash.bannerStyle', $result->flashStyle());
        }

        return SettleAfterInvite::flashAndRedirect(
            $request->user(),
            $result->flashBanner(),
            $result->flashStyle(),
        );
    }

    /**
     * Decline the invite.
     */
    public function decline(Request $request, Invite $invite): RedirectResponse
    {
        // Social HQ: someone in another company only drops the invite; their own account is never settled here.
        $staysInOwnCompany = $invite->isFor($request->user()->email) && AcceptInvite::holdsOtherAccount($request->user(), $invite);

        $result = DeclineInvite::execute($request->user(), $invite);

        if ($staysInOwnCompany) {
            return redirect()->route('app.home')
                ->with('flash.banner', $result->flashBanner())
                ->with('flash.bannerStyle', $result->flashStyle());
        }

        return SettleAfterInvite::flashAndRedirect(
            $request->user(),
            $result->flashBanner(),
            $result->flashStyle(),
        );
    }
}
