<?php

declare(strict_types=1);

namespace App\SocialHq\Signup;

use App\Actions\Auth\LogoutOtherSessions;
use App\Models\User;
use Illuminate\Auth\Events\Verified;

/**
 * A password reset proves the inbox, like a Google or GitHub sign-in. Someone
 * may have registered the address with their own password and stayed signed
 * in while waiting for access; the reset must end those sessions, or they
 * would get the account once an admin lets the real owner in.
 */
final class PasswordResetProof
{
    public static function handle(User $user): void
    {
        // The reset runs signed out, so every session of the user ends.
        LogoutOtherSessions::execute($user, '');

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }
    }
}
