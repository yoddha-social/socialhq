<?php

declare(strict_types=1);

namespace App\SocialHq\Signup;

use App\Actions\Auth\LogoutOtherSessions;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Socialite\AbstractUser;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * Google and GitHub sign-ins to an account found by its email.
 *
 * Anyone can register someone else's address with a password of their own
 * and leave it unconfirmed. When the address's real owner then signs in
 * through Google or GitHub, the provider has proved the email, so that proof
 * wins: the unconfirmed password, remember-me cookies and sessions stop
 * working, and the email counts as confirmed.
 */
final class OAuthSignIn
{
    /**
     * Whether Google says it verified the address (its `email_verified`
     * claim). GitHub only ever returns a verified primary address.
     */
    public static function googleVerifiedEmail(SocialiteUser $googleUser): bool
    {
        $claim = data_get($googleUser instanceof AbstractUser ? $googleUser->getRaw() : null, 'email_verified');

        return $claim === null || filter_var($claim, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Makes the provider's proof of the email win over an unconfirmed
     * account: a new random password nobody knows, a new remember token, the
     * account's other sessions ended, and the email marked confirmed (with
     * the Verified event, so admins hear about a waiting sign-up). Only when
     * the provider's address is the account's: an account linked to the
     * provider under another address keeps its password and stays
     * unconfirmed.
     */
    public static function claimUnverified(User $user, ?string $providerEmail, Request $request): void
    {
        if ($user->hasVerifiedEmail() || blank($providerEmail) || Str::lower($providerEmail) !== Str::lower((string) $user->email)) {
            return;
        }

        $user->forceFill([
            'password' => Str::random(64),
            'remember_token' => Str::random(60),
        ])->save();

        LogoutOtherSessions::execute($user, $request->hasSession() ? $request->session()->getId() : '');

        $user->markEmailAsVerified();

        event(new Verified($user));
    }

    /**
     * Google returned an address it hasn't verified: it proves nothing, so
     * it neither signs in to the account with that email nor creates one.
     */
    public static function unverifiedEmailRedirect(): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['email' => __('socialhq.signup.oauth_email_unverified')]);
    }
}
