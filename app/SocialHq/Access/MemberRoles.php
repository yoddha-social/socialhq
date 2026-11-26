<?php

declare(strict_types=1);

namespace App\SocialHq\Access;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Who may give whom which role. Nobody hands out more access than they hold:
 *
 * - giving a role (to a member or an invite) needs "Manage members" and every
 *   permission of that role;
 * - changing or removing a member needs "Manage members" and every
 *   permission the member holds now;
 * - nobody changes their own role or the owner's.
 *
 * The owner holds every permission, so these never stop them. Changing what a
 * role grants is the owner's alone (RoleAccess).
 */
final class MemberRoles
{
    public static function mayAssign(User $actor, Workspace $workspace, Role $role): bool
    {
        if ($role->account_id !== $workspace->account_id) {
            return false;
        }

        $access = WorkspaceAccess::of($actor, $workspace);

        return $access->allows(Permission::TeamManage) && $access->holdsAll($role->grantedPermissions());
    }

    public static function mayManage(User $actor, Workspace $workspace, string $memberId): bool
    {
        $memberId = Str::lower($memberId);

        if ($memberId === Str::lower($actor->id) || $memberId === Str::lower((string) $workspace->account?->owner_id)) {
            return false;
        }

        $access = WorkspaceAccess::of($actor, $workspace);

        if (! $access->allows(Permission::TeamManage)) {
            return false;
        }

        $member = User::query()->find($memberId);

        if ($member === null) {
            return false;
        }

        return $access->holdsAll(WorkspaceAccess::of($member, $workspace)->permissions);
    }

    /**
     * mayManage() for the members of a page at once: the members carry their
     * pivot (Workspace::members()) and the roles come keyed by id, so the
     * whole list costs one query.
     *
     * @param  Collection<int, User>  $members
     * @param  Collection<string, Role>  $roles
     * @return list<string>
     */
    public static function manageableIds(User $actor, Workspace $workspace, Collection $members, Collection $roles): array
    {
        $access = WorkspaceAccess::of($actor, $workspace);

        if (! $access->allows(Permission::TeamManage)) {
            return [];
        }

        $actorId = Str::lower($actor->id);
        $ownerId = Str::lower((string) $workspace->account?->owner_id);

        return $members
            ->filter(fn (User $member): bool => ! in_array(Str::lower($member->id), [$actorId, $ownerId], true)
                && $access->holdsAll(WorkspaceAccess::through($member, $workspace, $member->pivot, $roles)->permissions))
            ->map(fn (User $member): string => $member->id)
            ->values()
            ->all();
    }

    /**
     * The roles this person may give in the workspace (ids).
     *
     * @return list<string>
     */
    public static function assignableIds(User $actor, Workspace $workspace): array
    {
        $access = WorkspaceAccess::of($actor, $workspace);

        if (! $access->allows(Permission::TeamManage)) {
            return [];
        }

        return Roles::forAccount($workspace->account_id)
            ->filter(fn (Role $role): bool => $access->holdsAll($role->grantedPermissions()))
            ->pluck('id')
            ->values()
            ->all();
    }
}
