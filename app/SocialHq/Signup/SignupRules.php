<?php

declare(strict_types=1);

namespace App\SocialHq\Signup;

use App\Models\Account;
use App\Models\Invite;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Who may sign up without an invite (config socialhq.signup), and which
 * account they join. Self-hosted only: the SaaS mode keeps upstream's open
 * registration with an account per person.
 */
final class SignupRules
{
    /**
     * Mail providers anyone can get an address at. Listing one as a sign-up
     * domain would open sign-up to everybody, so they are ignored.
     */
    public const PUBLIC_DOMAINS = [
        'gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'live.com', 'msn.com',
        'yahoo.com', 'ymail.com', 'icloud.com', 'me.com', 'mac.com', 'aol.com', 'proton.me',
        'protonmail.com', 'gmx.com', 'gmx.net', 'mail.com', 'zoho.com', 'yandex.com', 'qq.com',
    ];

    /**
     * @return list<string>
     */
    public static function domains(): array
    {
        return collect(config('socialhq.signup.domains', []))
            ->map(fn (string $domain): string => Str::lower(ltrim(trim($domain), '@')))
            ->filter(fn (string $domain): bool => $domain !== '' && ! in_array($domain, self::PUBLIC_DOMAINS, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public static function emails(): array
    {
        return collect(config('socialhq.signup.emails', []))
            ->map(fn (string $email): string => Str::lower(trim($email)))
            ->filter(fn (string $email): bool => $email !== '')
            ->unique()
            ->values()
            ->all();
    }

    public static function isOpen(): bool
    {
        if (! config('trypost.self_hosted') || (self::domains() === [] && self::emails() === [])) {
            return false;
        }

        if (self::account() === null) {
            self::warnNoAccount();

            return false;
        }

        return true;
    }

    public static function allows(?string $email): bool
    {
        if (! self::isOpen() || blank($email)) {
            return false;
        }

        $email = Str::lower(trim($email));

        return in_array($email, self::emails(), true)
            || in_array(Str::after($email, '@'), self::domains(), true);
    }

    /**
     * Validation rule for the register form's email on a self-hosted
     * install: without an invite (posted, or kept in the session), only
     * allowed emails pass. Put it before `unique` with `bail`.
     *
     * @param  Closure(): bool  $hasPostedInvite
     * @return Closure(string, mixed, Closure(string): mixed): void
     */
    public static function allowListRule(Closure $hasPostedInvite): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($hasPostedInvite): void {
            if (! config('trypost.self_hosted') || $hasPostedInvite() || self::hasSessionInvite()) {
                return;
            }

            if (! self::allows(is_string($value) ? $value : null)) {
                $fail(__('socialhq.signup.not_allowed'));
            }
        };
    }

    /**
     * The account people who sign up join: the configured owner's, or else
     * the oldest account that owns a workspace. Every invited person has an
     * empty account of their own until they accept, so "the only account"
     * rarely exists. Looked up once per web request (never cached in a
     * queue worker or command, where one request object lives for hours).
     */
    public static function account(): ?Account
    {
        $owner = Str::lower(trim((string) config('socialhq.signup.account_owner')));
        $request = request();

        if ($request->route() === null) {
            return self::findAccount($owner);
        }

        $key = "socialhq.signup.account:{$owner}";

        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, self::findAccount($owner));
        }

        return $request->attributes->get($key);
    }

    private static function findAccount(string $owner): ?Account
    {
        return $owner !== ''
            ? self::accountOwnedBy($owner)
            : Account::query()->whereHas('workspaces')->oldest()->oldest('id')->first();
    }

    /**
     * Whether the session carries an open invite (an invite link opened
     * before signing up). A deleted or already accepted invite does not count.
     */
    public static function hasSessionInvite(): bool
    {
        $request = request();

        return $request->hasSession()
            && self::isOpenInvite(Invite::fromId((string) $request->session()->get('pending_invite_id')));
    }

    /**
     * An invite that still lets someone in: it exists and nobody accepted it yet.
     */
    public static function isOpenInvite(?Invite $invite): bool
    {
        return $invite !== null && $invite->accepted_at === null;
    }

    /**
     * The account a new user joins instead of getting their own: only for
     * self sign-up through the register form or Google/GitHub, never for
     * invites or users created by commands.
     */
    public static function accountToJoin(?string $email, bool $isInvite): ?Account
    {
        if ($isInvite || self::hasSessionInvite() || ! request()->routeIs('register.store', 'auth.google.callback', 'auth.github.callback')) {
            return null;
        }

        return self::allows($email) ? self::account() : null;
    }

    /**
     * The account of the user with this email, whatever its case (Google and
     * GitHub may send capitals). LIKE wildcards in the address are escaped,
     * so "a_b@" never matches "axb@".
     */
    private static function accountOwnedBy(string $email): ?Account
    {
        $pattern = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $email);

        return User::query()->whereLike('email', $pattern)->oldest()->first()?->account;
    }

    /**
     * The sign-up lists are set, but no account can take the people who sign
     * up: sign-up stays closed, and the log says why (at most once an hour).
     */
    private static function warnNoAccount(): void
    {
        if (Cache::add('socialhq:signup:no-account-warning', true, now()->addHour())) {
            Log::warning('Social HQ sign-up is closed: SOCIALHQ_SIGNUP_DOMAINS or SOCIALHQ_SIGNUP_EMAILS is set, but there is no account to join. Set SOCIALHQ_SIGNUP_ACCOUNT_OWNER to the account owner\'s email, or create a workspace first.');
        }
    }
}
