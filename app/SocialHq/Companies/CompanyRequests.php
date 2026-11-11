<?php

declare(strict_types=1);

namespace App\SocialHq\Companies;

use App\Enums\Notification\Type;
use App\Jobs\SendNotification;
use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Asking to start a company: opened at sign-up (or later, from an empty
 * account), sent to the platform admins once the owner's email is
 * confirmed, renamed while it waits, and asked again after a decline.
 */
final class CompanyRequests
{
    /**
     * How long platform admins hear about one email address only once, so
     * someone who keeps asking can't flood their inboxes.
     */
    private const ADMIN_MAIL_DECAY_SECONDS = 3600;

    public static function enabled(): bool
    {
        return (bool) config('trypost.self_hosted') && (bool) config('socialhq.companies.requests', true);
    }

    public static function open(User $user, Account $account, string $name): Company
    {
        $name = self::cleanName($name);

        $company = Company::query()->create([
            'account_id' => $account->id,
            'name' => $name,
            'status' => CompanyStatus::Pending,
            'uses_platform_ai' => false,
            'requested_by' => $user->id,
            'requested_at' => now(),
        ]);

        $account->update(['name' => $name]);
        $company->record(CompanyEvent::REQUESTED, $user);

        return $company;
    }

    /**
     * The waiting company this user owns, if any.
     */
    public static function waitingFor(User $user): ?Company
    {
        if (! $user->isAccountOwner()) {
            return null;
        }

        $company = Company::query()->where('account_id', $user->account_id)->first();

        return $company?->isWaiting() ? $company : null;
    }

    /**
     * Emails every platform admin once per request, when the owner's email
     * is confirmed, and at most once an hour per email address.
     */
    public static function notifyPlatformAdmins(User $user): void
    {
        $company = self::waitingFor($user);

        if ($company === null || $company->status !== CompanyStatus::Pending || $company->admins_notified_at !== null || ! $user->hasVerifiedEmail()) {
            return;
        }

        $company->update(['admins_notified_at' => now()]);

        $key = 'socialhq-company-request-mail:'.sha1(Str::lower((string) $user->email));

        if (RateLimiter::tooManyAttempts($key, 1)) {
            return;
        }

        RateLimiter::hit($key, self::ADMIN_MAIL_DECAY_SECONDS);

        PlatformAdmins::users()->each(fn (User $admin) => SendNotification::dispatch(
            $admin,
            Type::Collaboration,
            new Mail\CompanyRequested($company->id),
        )->afterCommit());
    }

    /**
     * A declined company asks again, at most once per waiting period: it
     * goes back to pending and the platform admins hear about it. False when
     * it isn't declined (any more) or it's too early.
     */
    public static function askAgain(Company $company, User $owner): bool
    {
        $asked = DB::transaction(function () use ($company, $owner): bool {
            $locked = Company::query()->whereKey($company->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== CompanyStatus::Declined || $locked->canAskAgainAt() !== null) {
                return false;
            }

            $locked->update([
                'status' => CompanyStatus::Pending,
                'requested_at' => now(),
                'admins_notified_at' => null,
                'decided_by' => null,
                'decided_at' => null,
                'decision_note' => null,
            ]);
            $locked->record(CompanyEvent::ASKED_AGAIN, $owner);

            return true;
        });

        if ($asked) {
            self::notifyPlatformAdmins($owner);
        }

        return $asked;
    }

    /**
     * Renames a company that still waits for a decision. False once it was decided.
     */
    public static function rename(Company $company, User $owner, string $name): bool
    {
        $name = self::cleanName($name);

        return DB::transaction(function () use ($company, $owner, $name): bool {
            $locked = Company::query()->whereKey($company->id)->lockForUpdate()->first();

            if ($locked === null || ! $locked->isWaiting()) {
                return false;
            }

            if ($locked->name !== $name) {
                $locked->update(['name' => $name]);
                $locked->account?->update(['name' => $name]);
                $locked->record(CompanyEvent::RENAMED, $owner);
            }

            return true;
        });
    }

    /**
     * Pending companies whose owner confirmed their email: the ones platform
     * admins act on.
     */
    public static function awaitingDecisionCount(): int
    {
        return Company::query()
            ->pending()
            ->whereHas('account.owner', fn ($owner) => $owner->whereNotNull('email_verified_at'))
            ->count();
    }

    private static function cleanName(string $name): string
    {
        return mb_substr(Str::squish($name), 0, 120);
    }
}
