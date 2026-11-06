<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth\Concerns;

use App\Models\Invite;
use App\SocialHq\Companies\CompanyRequests;
use App\SocialHq\Signup\SignupRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Carries the invite id across the OAuth round-trip via session, mirroring
 * PreservesAttributionParameters.
 */
trait PreservesInvite
{
    private function storeInvite(Request $request): void
    {
        $request->session()->put('oauth_invite_id', $request->string('invite')->toString());
    }

    private function retrieveInvite(): ?string
    {
        return session()->pull('oauth_invite_id');
    }

    /**
     * Mirrors the registration.enabled middleware: self-hosted requires a
     * real invite to register.
     */
    private function resolveInviteForRegistration(): ?Invite
    {
        $invite = Invite::fromId($this->retrieveInvite());

        if ((bool) config('trypost.self_hosted') && ! $invite && ! SignupRules::isOpen() && ! CompanyRequests::enabled()) {
            throw new NotFoundHttpException;
        }

        return $invite;
    }

    private function inviteEmailMismatchRedirect(?Invite $invite, ?string $oauthEmail): ?RedirectResponse
    {
        if ($invite && ! $invite->isFor($oauthEmail)) {
            return redirect()->route('login')->withErrors([
                'email' => __('settings.members.flash.wrong_email'),
            ]);
        }

        // Social HQ: with company requests on, anyone else asks to start a company (CreateUser).
        if (! $invite && (bool) config('trypost.self_hosted') && ! SignupRules::allows($oauthEmail) && ! CompanyRequests::enabled()) {
            return redirect()->route('login')->withErrors(['email' => __('socialhq.signup.not_allowed')]);
        }

        return null;
    }
}
