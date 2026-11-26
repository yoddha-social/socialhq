<?php

declare(strict_types=1);

namespace App\SocialHq\Access;

use App\Models\User;
use App\Models\Workspace;
use Closure;
use Illuminate\Support\Facades\Auth;

/**
 * The record of access changes (`shq_access_events`). The actor is whoever
 * is signed in for the request (null for the system: a migration, a job).
 * Each entry keeps names and permissions as they were, so it still reads
 * after the role or the person is gone.
 */
final class AccessLog
{
    /**
     * @param  list<string>|null  $before  what the role granted before the change
     */
    public static function roleSaved(Role $role, AccessEventAction $action, ?array $before = null, ?string $nameBefore = null): void
    {
        self::record($role->account_id, $action, roleId: $role->id, data: array_filter([
            'role' => $role->name,
            'name_before' => $nameBefore !== null && $nameBefore !== $role->name ? $nameBefore : null,
            'permissions_before' => $before,
            'permissions' => $role->grantedPermissions(),
        ], fn (mixed $value): bool => $value !== null));
    }

    public static function roleDeleted(Role $role, ?Role $movedTo, int $moved): void
    {
        self::record($role->account_id, AccessEventAction::RoleDeleted, roleId: $role->id, data: [
            'role' => $role->name,
            'permissions' => $role->grantedPermissions(),
            'moved' => $moved,
            'moved_to' => RoleResource::summary($movedTo),
        ]);
    }

    public static function memberRoleChanged(Workspace $workspace, string $memberId, ?Role $from, Role $to): void
    {
        self::record($workspace->account_id, AccessEventAction::MemberRoleChanged, $workspace, $to->id, $memberId, [
            'member' => self::person($memberId),
            'from' => RoleResource::summary($from),
            'to' => RoleResource::summary($to),
        ]);
    }

    public static function inviteSent(Workspace $workspace, string $email, Role $role): void
    {
        self::record($workspace->account_id, AccessEventAction::InviteSent, $workspace, $role->id, data: [
            'email' => $email,
            'role' => $role->name,
        ]);
    }

    public static function accessGranted(Workspace $workspace, User $member, Role $role): void
    {
        self::record($workspace->account_id, AccessEventAction::AccessGranted, $workspace, $role->id, $member->id, [
            'member' => ['name' => $member->name, 'email' => $member->email],
            'role' => $role->name,
        ]);
    }

    /**
     * Removing a member can delete their user, so the entry is read before
     * the removal and written by the returned callback once it succeeded.
     *
     * @return Closure(): void
     */
    public static function removalOf(Workspace $workspace, string $memberId): Closure
    {
        $role = Role::query()->find($workspace->members()->whereKey($memberId)->value('user_workspace.shq_role_id'));
        $data = ['member' => self::person($memberId), 'role' => $role?->name];

        return fn () => self::record($workspace->account_id, AccessEventAction::MemberRemoved, $workspace, $role?->id, $memberId, $data);
    }

    /**
     * @return array{name: string|null, email: string|null}
     */
    private static function person(string $userId): array
    {
        $user = User::query()->find($userId, ['name', 'email']);

        return ['name' => $user?->name, 'email' => $user?->email];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function record(
        string $accountId,
        AccessEventAction $action,
        ?Workspace $workspace = null,
        ?string $roleId = null,
        ?string $memberId = null,
        array $data = [],
    ): void {
        AccessEvent::query()->create([
            'account_id' => $accountId,
            'workspace_id' => $workspace?->id,
            'actor_id' => Auth::id(),
            'action' => $action,
            'role_id' => $roleId,
            'member_id' => $memberId,
            'data' => $data,
        ]);
    }
}
