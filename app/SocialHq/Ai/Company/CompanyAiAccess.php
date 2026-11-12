<?php

declare(strict_types=1);

namespace App\SocialHq\Ai\Company;

use App\Models\Account;
use App\Models\User;
use App\SocialHq\Companies\Company;
use Illuminate\Support\Facades\Gate;

/**
 * Who manages a company's AI: the owner of an approved company's account
 * (AccountPolicy::update). Workspace admins and members don't, and an
 * invited person's own empty account is not a company.
 */
final class CompanyAiAccess
{
    /**
     * The account whose AI this user manages, or null.
     */
    public static function accountFor(?User $user): ?Account
    {
        $account = $user?->account;

        if ($account === null || Gate::forUser($user)->denies('update', $account)) {
            return null;
        }

        return Company::forAccount($account)?->isApproved() ? $account : null;
    }

    public static function canManage(?User $user): bool
    {
        return self::accountFor($user) !== null;
    }
}
