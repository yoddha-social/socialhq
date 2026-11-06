<?php

declare(strict_types=1);

namespace App\SocialHq\Companies;

use App\Enums\Notification\Type;
use App\Jobs\SendNotification;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;

/**
 * Platform admins' decisions about a company. Each one locks the company's
 * row and checks its status first, so when two admins decide at once the
 * first wins and the second gets false (nothing changes then). Every
 * decision is recorded in shq_company_events.
 */
final class CompanyDecisions
{
    /**
     * Lets a pending company in: its owner can create workspaces. Only once
     * the owner confirmed their email.
     */
    public static function approve(Company $company, User $admin): bool
    {
        $decided = self::decide($company, $admin, CompanyStatus::Pending, CompanyStatus::Approved, CompanyEvent::APPROVED, null, requireVerifiedOwner: true);

        if ($decided !== null) {
            self::tellOwner($decided, new Mail\CompanyApproved($decided->id));
        }

        return $decided !== null;
    }

    public static function decline(Company $company, User $admin, string $note): bool
    {
        $decided = self::decide($company, $admin, CompanyStatus::Pending, CompanyStatus::Declined, CompanyEvent::DECLINED, $note);

        if ($decided !== null) {
            self::tellOwner($decided, new Mail\CompanyDeclined($decided->id));
        }

        return $decided !== null;
    }

    /**
     * Stops an approved company: nobody in it can use the app until it is restored.
     */
    public static function suspend(Company $company, User $admin, string $note): bool
    {
        return self::decide($company, $admin, CompanyStatus::Approved, CompanyStatus::Suspended, CompanyEvent::SUSPENDED, $note) !== null;
    }

    public static function restore(Company $company, User $admin): bool
    {
        return self::decide($company, $admin, CompanyStatus::Suspended, CompanyStatus::Approved, CompanyEvent::RESTORED, null) !== null;
    }

    /**
     * Whether the company may use the platform's own AI keys. Setting the
     * value it already has records nothing.
     */
    public static function usePlatformAi(Company $company, User $admin, bool $enabled): void
    {
        DB::transaction(function () use ($company, $admin, $enabled): void {
            $locked = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();

            if ($locked->uses_platform_ai === $enabled) {
                return;
            }

            $locked->update(['uses_platform_ai' => $enabled]);
            $locked->record($enabled ? CompanyEvent::PLATFORM_AI_ON : CompanyEvent::PLATFORM_AI_OFF, $admin);
        });
    }

    /**
     * @return Company|null the decided company, or null when its status was no longer $from
     */
    private static function decide(Company $company, User $admin, CompanyStatus $from, CompanyStatus $to, string $action, ?string $note, bool $requireVerifiedOwner = false): ?Company
    {
        return DB::transaction(function () use ($company, $admin, $from, $to, $action, $note, $requireVerifiedOwner): ?Company {
            $locked = Company::query()->whereKey($company->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== $from) {
                return null;
            }

            if ($requireVerifiedOwner && $locked->account?->owner?->hasVerifiedEmail() !== true) {
                return null;
            }

            $locked->update([
                'status' => $to,
                'decided_by' => $admin->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);
            $locked->record($action, $admin, $note);

            return $locked;
        });
    }

    private static function tellOwner(Company $company, Mailable $mailable): void
    {
        $owner = $company->account?->owner;

        if ($owner instanceof User) {
            SendNotification::dispatch($owner, Type::Collaboration, $mailable)->afterCommit();
        }
    }
}
