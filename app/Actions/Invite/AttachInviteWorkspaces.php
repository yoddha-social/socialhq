<?php

declare(strict_types=1);

namespace App\Actions\Invite;

use App\Models\Invite;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Access\BuiltInRole;
use App\SocialHq\Access\Role;
use App\SocialHq\Access\Roles;
use Illuminate\Support\Collection;

class AttachInviteWorkspaces
{
    /**
     * @param  Collection<int, Workspace>  $workspaces
     */
    public static function execute(User $user, Invite $invite, Collection $workspaces): void
    {
        foreach ($workspaces as $workspace) {
            $alreadyMember = $workspace->members()
                ->where('users.id', $user->id)
                ->exists();

            if (! $alreadyMember) {
                // Social HQ: the invite's role, or the built-in role matching its flags.
                $workspace->members()->attach($user->id, Roles::membershipColumns(self::roleFor($invite, $workspace)));
            }

            // Accept often switches account_id while an old personal workspace is
            // still current — always land on a workspace of the invite account.
            if (! self::currentWorkspaceBelongsToAccount($user)) {
                $user->update(['current_workspace_id' => $workspace->id]);
                $user->refresh();
            }
        }
    }

    /**
     * Social HQ: the role an invite joins with in this workspace. An invite
     * from before roles picks the built-in role its flags match; one whose
     * role isn't in this company joins as Viewer (fail closed).
     */
    private static function roleFor(Invite $invite, Workspace $workspace): Role
    {
        if ($invite->shq_role_id === null) {
            return Roles::forFlags($workspace->account_id, (bool) $invite->is_admin, (bool) $invite->requires_approval);
        }

        return Role::query()->forAccount($workspace->account_id)->find($invite->shq_role_id)
            ?? Roles::builtIn($workspace->account_id, BuiltInRole::Viewer);
    }

    public static function currentWorkspaceBelongsToAccount(User $user): bool
    {
        if (! $user->current_workspace_id || ! $user->account_id) {
            return false;
        }

        return $user->workspaces()
            ->where('workspaces.id', $user->current_workspace_id)
            ->where('workspaces.account_id', $user->account_id)
            ->exists();
    }
}
