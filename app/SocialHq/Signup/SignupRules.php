<?php

declare(strict_types=1);

namespace App\SocialHq\Signup;

use App\Models\Account;
use App\Models\User;
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
        return (bool) config('trypost.self_hosted')
            && (self::domains() !== [] || self::emails() !== [])
            && self::account() !== null;
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
     * The account people who sign up join: the configured owner's, or the
     * only account there is.
     */
    public static function account(): ?Account
    {
        $owner = config('socialhq.signup.account_owner');

        if (filled($owner)) {
            return User::query()->where('email', Str::lower(trim((string) $owner)))->first()?->account;
        }

        return Account::query()->count() === 1 ? Account::query()->first() : null;
    }

    /**
     * The account a new user joins instead of getting their own: only for
     * self sign-up through the register form or Google/GitHub, never for
     * invites or users created by commands.
     */
    public static function accountToJoin(?string $email, bool $isInvite): ?Account
    {
        $hasInvite = $isInvite || filled(request()->hasSession() ? request()->session()->get('pending_invite_id') : null);

        if ($hasInvite || ! request()->routeIs('register.store', 'auth.google.callback', 'auth.github.callback')) {
            return null;
        }

        return self::allows($email) ? self::account() : null;
    }
}
