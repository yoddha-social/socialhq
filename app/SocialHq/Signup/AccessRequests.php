<?php

declare(strict_types=1);

namespace App\SocialHq\Signup;

use App\Actions\Media\DeleteOrphanedMediaFiles;
use App\Actions\User\PurgeUserAccess;
use App\Enums\Notification\Type;
use App\Jobs\SendNotification;
use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * The life of an access request: opened at sign-up, sent to the admins once
 * the email is confirmed, then approved (workspaces given) or declined (the
 * user is deleted, the request kept as history). A person who joins a
 * workspace another way (an invite) has their request closed for them.
 */
final class AccessRequests
{
    /**
     * Note on a request closed because its person joined through an invite.
     */
    public const JOINED_BY_INVITE = 'joined_by_invite';

    /**
     * How long admins hear about one email address only once, so someone
     * who is declined and signs up again can't flood their inboxes.
     */
    private const ADMIN_MAIL_DECAY_SECONDS = 3600;

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
     * Emails the account's admins once per request, when its email is
     * confirmed, and at most once an hour per email address.
     */
    public static function notifyAdmins(User $user): void
    {
        $request = self::pendingFor($user);

        if ($request === null || $request->admins_notified_at !== null || ! $user->hasVerifiedEmail()) {
            return;
        }

        $request->update(['admins_notified_at' => now()]);

        $key = 'socialhq-access-request-mail:'.sha1(Str::lower($request->email));

        if (RateLimiter::tooManyAttempts($key, 1)) {
            return;
        }

        RateLimiter::hit($key, self::ADMIN_MAIL_DECAY_SECONDS);

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
     * Requests waiting for this user's decision, in one query: 0 for anyone
     * who is neither the account owner nor an admin of one of its workspaces.
     */
    public static function pendingCountFor(User $user): int
    {
        if ($user->account_id === null) {
            return 0;
        }

        return AccessRequest::query()
            ->where('account_id', $user->account_id)
            ->awaitingDecision()
            ->where(fn (Builder $decider) => $decider
                ->whereHas('account', fn (Builder $account) => $account->where('owner_id', $user->id))
                ->orWhereHas('account.workspaces.members', fn (Builder $member) => $member
                    ->where('users.id', $user->id)
                    ->where('user_workspace.is_admin', true)))
            ->count();
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
     * Gives the person the picked workspaces. False when someone else
     * decided on the request first: nothing changes then.
     *
     * @param  list<string>  $workspaceIds  workspaces the approver manages
     */
    public static function approve(AccessRequest $request, User $approver, array $workspaceIds, bool $isAdmin): bool
    {
        $workspaces = self::workspacesManagedBy($approver)->whereIn('id', $workspaceIds)->values();

        if ($workspaces->isEmpty()) {
            return false;
        }

        $user = DB::transaction(function () use ($request, $approver, $workspaces, $isAdmin): ?User {
            $locked = self::lockPending($request);
            $user = $locked?->user;

            if ($locked === null || $user === null) {
                return null;
            }

            // Decided first, so the "joined a workspace" hook finds nothing left to close.
            $locked->update([
                'status' => AccessRequest::APPROVED,
                'decided_by' => $approver->id,
                'decided_at' => now(),
            ]);

            foreach ($workspaces as $workspace) {
                $user->workspaces()->syncWithoutDetaching([$workspace->id => [
                    'is_admin' => $isAdmin,
                    // Someone who signed up is reviewed until an admin trusts them more.
                    'requires_approval' => ! $isAdmin,
                ]]);
            }

            $user->update(['current_workspace_id' => $workspaces->first()->id]);

            return $user;
        });

        if ($user === null) {
            return false;
        }

        SendNotification::dispatch($user, Type::Collaboration, new Mail\AccessApproved($user->id))->afterCommit();

        return true;
    }

    /**
     * Deletes the person and keeps the request as history. False when
     * someone else decided on the request first: nothing changes then.
     */
    public static function decline(AccessRequest $request, User $approver): bool
    {
        $mediaPaths = DB::transaction(function () use ($request, $approver): ?array {
            $locked = self::lockPending($request);

            if ($locked === null) {
                return null;
            }

            $locked->update([
                'status' => AccessRequest::DECLINED,
                'decided_by' => $approver->id,
                'decided_at' => now(),
            ]);

            $user = $locked->user;

            if ($user === null) {
                return [];
            }

            $paths = PurgeUserAccess::execute($user);
            $user->delete();

            return $paths;
        });

        if ($mediaPaths === null) {
            return false;
        }

        // Files go once the rows are gone for good, as DeleteUser does.
        DeleteOrphanedMediaFiles::execute($mediaPaths);

        return true;
    }

    /**
     * Someone waiting for access got a workspace another way (they accepted
     * an invite): their request is closed, and the waiting page lets them go.
     */
    public static function closeOnceJoined(User $user): void
    {
        if ($user->current_workspace_id === null) {
            return;
        }

        AccessRequest::query()
            ->where('user_id', $user->id)
            ->where('status', AccessRequest::PENDING)
            ->update([
                'status' => AccessRequest::APPROVED,
                'decided_at' => now(),
                'note' => self::JOINED_BY_INVITE,
                'updated_at' => now(),
            ]);
    }

    /**
     * The request, locked, while it still waits for a decision.
     */
    private static function lockPending(AccessRequest $request): ?AccessRequest
    {
        return AccessRequest::query()
            ->whereKey($request->id)
            ->where('status', AccessRequest::PENDING)
            ->lockForUpdate()
            ->first();
    }
}
