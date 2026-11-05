<?php

declare(strict_types=1);

namespace App\SocialHq\Signup;

use App\Actions\User\PurgeUserAccess;
use App\Enums\Notification\Type;
use App\Jobs\SendNotification;
use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The life of an access request: opened at sign-up, sent to the admins once
 * the email is confirmed, then approved (workspaces given) or declined (the
 * user is deleted, the request kept as history).
 */
final class AccessRequests
{
    public static function open(User $user, Account $account): AccessRequest
    {
        return AccessRequest::query()->create([
            'account_id' => $account->id,
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'status' => AccessRequest::PENDING,
        ]);
    }

    public static function pendingFor(User $user): ?AccessRequest
    {
        return AccessRequest::query()->where('user_id', $user->id)->where('status', AccessRequest::PENDING)->first();
    }

    /**
     * Emails the account's admins once per request, when its email is confirmed.
     */
    public static function notifyAdmins(User $user): void
    {
        $request = self::pendingFor($user);

        if ($request === null || $request->admins_notified_at !== null || ! $user->hasVerifiedEmail()) {
            return;
        }

        $request->update(['admins_notified_at' => now()]);

        self::adminsOf($request->account)->each(fn (User $admin) => SendNotification::dispatch(
            $admin,
            Type::Collaboration,
            new Mail\AccessRequested($request->id),
        )->afterCommit());
    }

    /**
     * The account owner and every admin of one of its workspaces.
     *
     * @return Collection<int, User>
     */
    public static function adminsOf(Account $account): Collection
    {
        return User::query()
            ->where('account_id', $account->id)
            ->where(fn (Builder $query) => $query
                ->whereKey($account->owner_id)
                ->orWhereHas('workspaces', fn (Builder $workspace) => $workspace
                    ->where('workspaces.account_id', $account->id)
                    ->where('user_workspace.is_admin', true)))
            ->get();
    }

    public static function canDecide(User $user): bool
    {
        return $user->isAccountOwner() || self::workspacesManagedBy($user)->isNotEmpty();
    }

    /**
     * Workspaces this user may give access to: all of the account's for the
     * owner, the ones they administer otherwise.
     *
     * @return Collection<int, Workspace>
     */
    public static function workspacesManagedBy(User $user): Collection
    {
        $query = Workspace::query()->where('account_id', $user->account_id)->orderBy('name');

        if (! $user->isAccountOwner()) {
            $query->whereHas('members', fn (Builder $member) => $member
                ->where('users.id', $user->id)
                ->where('user_workspace.is_admin', true));
        }

        return $query->get();
    }

    /**
     * @param  list<string>  $workspaceIds  workspaces the approver manages
     */
    public static function approve(AccessRequest $request, User $approver, array $workspaceIds, bool $isAdmin): void
    {
        $user = $request->user;
        $workspaces = self::workspacesManagedBy($approver)->whereIn('id', $workspaceIds)->values();

        DB::transaction(function () use ($request, $approver, $user, $workspaces, $isAdmin): void {
            foreach ($workspaces as $workspace) {
                $user->workspaces()->syncWithoutDetaching([$workspace->id => [
                    'is_admin' => $isAdmin,
                    // Someone who signed up is reviewed until an admin trusts them more.
                    'requires_approval' => ! $isAdmin,
                ]]);
            }

            $user->update(['current_workspace_id' => $workspaces->first()->id]);

            $request->update([
                'status' => AccessRequest::APPROVED,
                'decided_by' => $approver->id,
                'decided_at' => now(),
            ]);
        });

        SendNotification::dispatch($user, Type::Collaboration, new Mail\AccessApproved($user->id))->afterCommit();
    }

    public static function decline(AccessRequest $request, User $approver): void
    {
        $user = $request->user;

        DB::transaction(function () use ($request, $approver, $user): void {
            $request->update([
                'status' => AccessRequest::DECLINED,
                'decided_by' => $approver->id,
                'decided_at' => now(),
            ]);

            if ($user !== null) {
                PurgeUserAccess::execute($user);
                $user->delete();
            }
        });
    }
}
