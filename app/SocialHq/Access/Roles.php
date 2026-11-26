<?php

declare(strict_types=1);

namespace App\SocialHq\Access;

use App\Actions\AccessToken\RevokeWorkspaceApiKeys;
use App\Models\Workspace;
use App\SocialHq\Approval\ApprovalRules;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A company's roles, and the one place memberships get a role.
 *
 * A membership keeps `is_admin` and `requires_approval` next to its role, as
 * mirrors written from the role (is_admin: may manage members; requires
 * approval: may not publish directly). Code that still reads the flags (the
 * access-request queries, TryPost's API) then agrees with the role.
 */
final class Roles
{
    /**
     * The company's built-in roles, created the first time they are needed.
     *
     * @return Collection<string, Role> keyed by BuiltInRole value
     */
    public static function ensureBuiltIns(string $accountId): Collection
    {
        $existing = Role::query()->forAccount($accountId)->whereNotNull('key')->get()->keyBy(fn (Role $role): string => $role->key->value);

        foreach (BuiltInRole::cases() as $builtIn) {
            if ($existing->has($builtIn->value)) {
                continue;
            }

            try {
                // Its own transaction (a savepoint inside another), so a unique
                // violation doesn't abort the caller's transaction on PostgreSQL.
                $role = DB::transaction(function () use ($accountId, $builtIn): Role {
                    $role = new Role;
                    $role->forceFill([
                        'account_id' => $accountId,
                        'key' => $builtIn,
                        'name' => $builtIn->defaultName(),
                        'description' => null,
                        'permissions' => $builtIn->defaultPermissions(),
                        'position' => $builtIn->position(),
                    ])->save();

                    return $role;
                });
            } catch (QueryException) {
                // Created meanwhile by another request (unique account + key), or a
                // custom role took the name: read what is there.
                $role = Role::query()->forAccount($accountId)->where('key', $builtIn->value)->first();
            }

            if ($role !== null) {
                $existing->put($builtIn->value, $role);
            }
        }

        return $existing;
    }

    public static function builtIn(string $accountId, BuiltInRole $builtIn): Role
    {
        $role = self::ensureBuiltIns($accountId)->get($builtIn->value);

        abort_if($role === null, 500, "Built-in role {$builtIn->value} is missing.");

        return $role;
    }

    /**
     * Every role of the company, built-in ones first.
     *
     * @return Collection<int, Role>
     */
    public static function forAccount(string $accountId): Collection
    {
        self::ensureBuiltIns($accountId);

        return Role::query()->forAccount($accountId)->ordered()->get();
    }

    /**
     * The built-in role matching TryPost's membership flags, for callers that
     * still speak in flags (the "Edit access" dialog, access requests).
     */
    public static function forFlags(string $accountId, bool $isAdmin, bool $requiresApproval): Role
    {
        return self::builtIn($accountId, match (true) {
            $isAdmin => BuiltInRole::Admin,
            ApprovalRules::memberPublishesDirectly($requiresApproval) => BuiltInRole::Manager,
            default => BuiltInRole::Editor,
        });
    }

    /**
     * The flag columns that mirror a role.
     *
     * @return array{shq_role_id: string, is_admin: bool, requires_approval: bool}
     */
    public static function membershipColumns(Role $role): array
    {
        return [
            'shq_role_id' => $role->id,
            'is_admin' => $role->grants(Permission::TeamManage),
            'requires_approval' => ! $role->grants(Permission::PostsPublish),
        ];
    }

    /**
     * Gives a member of the workspace this role, and logs the change. Their
     * API keys for the workspace stop working when the role can't manage
     * API keys.
     *
     * The role is read again under a shared lock: a change to what it grants
     * (RoleChanges::update(), which locks it for update) then waits for this
     * write or runs before it, so the flag mirrors always match the role.
     */
    public static function assign(Workspace $workspace, string $userId, Role $role): void
    {
        abort_unless($role->account_id === $workspace->account_id, 404);

        DB::transaction(function () use ($workspace, $userId, $role): void {
            $current = Role::query()->sharedLock()->findOrFail($role->id);
            $membership = DB::table('user_workspace')
                ->where('workspace_id', $workspace->id)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first(['shq_role_id']);

            if ($membership === null) {
                return;
            }

            DB::table('user_workspace')
                ->where('workspace_id', $workspace->id)
                ->where('user_id', $userId)
                ->update([...self::membershipColumns($current), 'updated_at' => now()]);

            if (! $current->grants(Permission::ApiKeysManage)) {
                RevokeWorkspaceApiKeys::forUserOnWorkspace($userId, $workspace);
            }

            if ($membership->shq_role_id !== $current->id) {
                $from = $membership->shq_role_id === null ? null : Role::query()->find($membership->shq_role_id);
                AccessLog::memberRoleChanged($workspace, $userId, $from, $current);
            }
        });
    }

    /**
     * After a role's permissions changed: rewrite the mirrors of everyone who
     * has it, and stop the API keys of those who no longer may hold them.
     */
    public static function applyChange(Role $role): void
    {
        $columns = self::membershipColumns($role);
        unset($columns['shq_role_id']);

        DB::table('user_workspace')->where('shq_role_id', $role->id)->update($columns);
        DB::table('invites')->where('shq_role_id', $role->id)->update($columns);

        if ($role->grants(Permission::ApiKeysManage)) {
            return;
        }

        DB::table('user_workspace')
            ->where('shq_role_id', $role->id)
            ->select(['user_id', 'workspace_id'])
            ->orderBy('user_id')
            ->orderBy('workspace_id')
            ->chunk(200, function (Collection $memberships): void {
                $workspaces = Workspace::query()->whereIn('id', $memberships->pluck('workspace_id')->unique()->all())->get()->keyBy('id');

                foreach ($memberships as $membership) {
                    $workspace = $workspaces->get($membership->workspace_id);

                    if ($workspace !== null) {
                        RevokeWorkspaceApiKeys::forUserOnWorkspace($membership->user_id, $workspace);
                    }
                }
            });
    }
}
