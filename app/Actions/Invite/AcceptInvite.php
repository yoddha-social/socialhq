<?php

declare(strict_types=1);

namespace App\Actions\Invite;

use App\Actions\Account\CancelAccountSubscription;
use App\Enums\Invite\Result;
use App\Models\Account;
use App\Models\Invite;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Companies\Company;
use Illuminate\Support\Facades\DB;

class AcceptInvite
{
    public static function execute(User $user, Invite $invite): Result
    {
        if (! $invite->isFor($user->email)) {
            return Result::WrongEmail;
        }

        $previousAccountId = null;

        $result = DB::transaction(function () use ($user, $invite, &$previousAccountId): Result {
            $lockedInvite = Invite::query()
                ->whereKey($invite->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedInvite) {
                return Result::Gone;
            }

            if ($lockedInvite->accepted_at !== null) {
                return Result::AlreadyAccepted;
            }

            $workspaces = ResolveInviteWorkspaces::execute($lockedInvite);

            if ($workspaces->isEmpty()) {
                $lockedInvite->delete();

                return Result::Gone;
            }

            // Already on the account: still attach any missing workspace memberships.
            if ($user->account_id === $lockedInvite->account_id) {
                AttachInviteWorkspaces::execute($user, $lockedInvite, $workspaces);

                $lockedInvite->update(['accepted_at' => now()]);

                return Result::AlreadyMember;
            }

            // Social HQ: invites go to any address (the inviter never learns
            // whether it has an account), so someone whose account holds
            // anything stays there; only an empty account moves.
            if (self::holdsOtherAccount($user, $lockedInvite)) {
                return Result::OtherAccount;
            }

            $previousAccountId = $user->account_id;

            $user->update(['account_id' => $lockedInvite->account_id]);
            $user->refresh();

            AttachInviteWorkspaces::execute($user, $lockedInvite, $workspaces);

            $lockedInvite->update(['accepted_at' => now()]);

            return Result::Accepted;
        });

        // Invite signup leaves an empty personal account shell. Drop it after
        // commit so Stripe cancel is not held inside the invite lock. Members
        // never own a non-empty account, so there is nothing else to tear down.
        if ($result === Result::Accepted && $previousAccountId) {
            $shell = Account::query()
                ->whereKey($previousAccountId)
                ->where('owner_id', $user->id)
                ->whereDoesntHave('workspaces')
                ->first();

            if ($shell && CancelAccountSubscription::execute($shell)) {
                $shell->delete();
            }
        }

        return $result;
    }

    /**
     * Social HQ: whether the user belongs to another account that holds
     * something (a workspace, another person or a company), so accepting
     * would take them out of it.
     */
    public static function holdsOtherAccount(User $user, Invite $invite): bool
    {
        $accountId = $user->account_id;

        if ($accountId === null || $accountId === $invite->account_id) {
            return false;
        }

        return Workspace::query()->where('account_id', $accountId)->exists()
            || User::query()->where('account_id', $accountId)->whereKeyNot($user->id)->exists()
            || Company::query()->where('account_id', $accountId)->exists();
    }
}
