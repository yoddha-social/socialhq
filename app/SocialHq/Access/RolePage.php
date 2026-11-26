<?php

declare(strict_types=1);

namespace App\SocialHq\Access;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Props of Settings › Members › Roles. The list, one role and the compare
 * table share them. Whoever may give roles in the workspace sees the roles
 * (WorkspacePolicy::manageTeam); only the owner changes them (RoleAccess).
 * The owner sees who has each role in every workspace of the company,
 * anyone else only in the current workspace.
 */
final class RolePage
{
    /**
     * @return array<string, mixed>
     */
    public static function props(User $viewer, Workspace $workspace): array
    {
        $canManage = RoleAccess::mayManage($viewer) && $viewer->ownsAccountOf($workspace);
        $owner = $workspace->account?->owner;

        /** @var Collection<string, string> $workspaces id => name */
        $workspaces = $canManage
            ? Workspace::query()->where('account_id', $workspace->account_id)->pluck('name', 'id')
            : collect([$workspace->id => $workspace->name]);

        $people = self::people($workspaces, $owner?->id);
        $invites = $canManage ? self::pendingInvites((string) $workspace->account_id) : collect();

        return [
            'canManage' => $canManage,
            'scope' => $canManage ? 'company' : 'workspace',
            'company' => $workspace->account?->name,
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'owner' => $owner === null ? null : [
                'id' => $owner->id,
                'name' => $owner->name,
                'photo_url' => $owner->photo_url,
            ],
            'catalog' => Permission::catalog(),
            'limits' => [
                'custom' => Role::MAX_CUSTOM,
                'name' => Role::NAME_MAX,
                'description' => Role::DESCRIPTION_MAX,
            ],
            'roles' => Roles::forAccount((string) $workspace->account_id)
                ->map(fn (Role $role): array => [
                    ...RoleResource::make($role),
                    'people' => $people->get($role->id, []),
                    'pending_invites' => (int) $invites->get($role->id, 0),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Who has each role in these workspaces, by role id. The owner has every
     * permission whatever their membership says, so they're never listed.
     *
     * @param  Collection<string, string>  $workspaces  id => name
     * @return Collection<string, list<array{id: string, name: string, photo_url: string|null, workspace_id: string, workspace: string}>>
     */
    private static function people(Collection $workspaces, ?string $ownerId): Collection
    {
        return User::query()
            ->with('avatarMedia')
            ->join('user_workspace', 'user_workspace.user_id', '=', 'users.id')
            ->whereIn('user_workspace.workspace_id', $workspaces->keys()->all())
            ->whereNotNull('user_workspace.shq_role_id')
            ->when($ownerId !== null, fn (Builder $query): Builder => $query->where('users.id', '!=', $ownerId))
            ->orderBy('users.name')
            ->get(['users.*', 'user_workspace.shq_role_id as membership_role_id', 'user_workspace.workspace_id as membership_workspace_id'])
            ->groupBy('membership_role_id')
            ->map(fn (Collection $members): array => $members->map(fn (User $member): array => [
                'id' => $member->id,
                'name' => $member->name,
                'photo_url' => $member->photo_url,
                'workspace_id' => (string) $member->getAttribute('membership_workspace_id'),
                'workspace' => (string) $workspaces->get($member->getAttribute('membership_workspace_id')),
            ])->values()->all());
    }

    /**
     * Invites not yet accepted, counted by role id.
     *
     * @return Collection<string, int>
     */
    private static function pendingInvites(string $accountId): Collection
    {
        return DB::table('invites')
            ->where('account_id', $accountId)
            ->whereNull('accepted_at')
            ->whereNotNull('shq_role_id')
            ->pluck('shq_role_id')
            ->countBy();
    }
}
