<?php

declare(strict_types=1);

namespace App\SocialHq\Companies;

use App\Actions\User\CreateUser;
use App\Models\User;
use App\SocialHq\Companies\Mail\AccountExists;
use App\SocialHq\Signup\SignupRules;
use Closure;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Signing up to ask for a company: someone whose email isn't on the sign-up
 * lists (SignupRules) and who has no invite registers with a company name.
 *
 * The register form never says whether the email has an account: the
 * company path skips `unique`, never logs in, and ends on the same "check
 * your email" page either way. A new email gets its account and pending
 * company and the confirmation link; an email that has an account gets
 * "you already have an account" with a password reset link (AccountExists).
 *
 * Google and GitHub sign-ups prove the email, so they create the account
 * and the pending company at once, named after the person; the owner can
 * rename it on the waiting page.
 */
final class CompanySignup
{
    public const SESSION_EMAIL = 'socialhq.company_request_email';

    /**
     * Whether this registration asks for a company: company requests are
     * on, there's no invite (posted or in the session: RegisterRequest::invite()),
     * and the email isn't on the sign-up lists (those join their team's
     * company, as before).
     *
     * @param  Closure(): bool  $hasInvite
     */
    public static function asksForCompany(mixed $email, Closure $hasInvite): bool
    {
        return CompanyRequests::enabled()
            && ! $hasInvite()
            && ! SignupRules::allows(is_string($email) ? $email : null);
    }

    /**
     * Whether the register page shows the company name field. The server
     * still decides on submit (asksForCompany).
     */
    public static function showsCompanyField(): bool
    {
        return CompanyRequests::enabled() && ! SignupRules::hasSessionInvite();
    }

    /**
     * @param  array{name: string, email: string, password: string, company_name: string, registration_ip?: string|null, locale?: string|null, timezone?: string|null, week_starts_on?: string|null, time_format?: string|null}  $data
     * @param  array<string, string>  $attributionParameters
     */
    public static function register(array $data, array $attributionParameters): RedirectResponse
    {
        $email = Str::lower((string) data_get($data, 'email'));

        if (User::query()->where('email', $email)->exists()) {
            self::sendResetLink($email);
        } else {
            try {
                $user = CreateUser::execute([...$data, 'self_signup' => true], $attributionParameters);
                event(new Registered($user));
            } catch (UniqueConstraintViolationException) {
                // Someone registered the same email a moment earlier.
                self::sendResetLink($email);
            }
        }

        session()->put(self::SESSION_EMAIL, $email);

        return redirect()->route('socialhq.company.requested');
    }

    /**
     * The company name CreateUser opens a request with for a self sign-up
     * that doesn't join its team (the allow list), or null when company
     * requests are off (or in the SaaS mode). CreateUser asks only for self
     * sign-ups, never for invites or users created by commands.
     *
     * @param  array<string, mixed>  $data
     */
    public static function companyToRequest(array $data): ?string
    {
        if (! CompanyRequests::enabled()) {
            return null;
        }

        $name = Str::squish((string) data_get($data, 'company_name'));

        if ($name === '') {
            $name = Str::squish((string) data_get($data, 'name'));
        }

        return $name !== '' ? $name : Str::before((string) data_get($data, 'email'), '@');
    }

    /**
     * The account's owner hears that someone tried to sign up with their
     * address, with a reset link, at most once a minute (the password
     * broker's throttle).
     */
    private static function sendResetLink(string $email): void
    {
        // About the time a new account takes to hash its password.
        Hash::make(Str::random(32));

        rescue(function () use ($email): void {
            $broker = Password::broker();
            $user = $broker->getUser(['email' => $email]);

            if (! $user instanceof User || $broker->getRepository()->recentlyCreatedToken($user)) {
                return;
            }

            $resetUrl = route('password.reset', ['token' => $broker->createToken($user), 'email' => $user->getEmailForPasswordReset()]);

            Mail::to($user)->send(new AccountExists($user->name, $user->email, $resetUrl));
        }, report: false);
    }
}
