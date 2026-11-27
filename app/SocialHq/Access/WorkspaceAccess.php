<?php

declare(strict_types=1);

namespace App\SocialHq\Access;

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Approval\ApprovalRules;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What one person may do in one workspace, from one read of their membership
 * and its role:
 *
 * - the account owner holds every permission;
 * - a member holds their role's permissions;
 * - a membership without a role (rows written before roles, or by code that
 *   only sets the flags) falls back to its flags, exactly as before roles:
 *   an admin holds everything, a member who publishes directly what Manager
 *   held then (without brand.edit), any other member writes and plans;
 * - anyone else, a role of another company, or a role id whose role is
 *   gone, holds nothing (fail closed, never the flags).
 *
 * Seeing the workspace comes with membership ($isMember). Agents (API keys,
 * MCP) are capped on top of this by ApprovalRules and AdminSettings.
 */
final readonly class WorkspaceAccess
{
    /**
     * @param  list<string>  $permissions
     */
    private function __construct(
        public bool $isOwner,
        public bool $isMember,
        public ?Role $role,
        public array $permissions,
    ) {}

    public static function of(User $user, Workspace $workspace): self
    {
        if ($workspace->account_id !== $user->account_id) {
            return self::none();
        }

        if ($user->ownsAccountOf($workspace)) {
            return self::owner();
        }

        $membership = DB::table('user_workspace')
            ->leftJoin('shq_roles', 'shq_roles.id', '=', 'user_workspace.shq_role_id')
            ->where('user_workspace.user_id', $user->id)
            ->where('user_workspace.workspace_id', $workspace->id)
            ->first([
                'user_workspace.is_admin',
                'user_workspace.requires_approval',
                'user_workspace.shq_role_id',
                'shq_roles.id as role_id',
                'shq_roles.account_id as role_account_id',
                'shq_roles.key as role_key',
                'shq_roles.name as role_name',
                'shq_roles.description as role_description',
                'shq_roles.permissions as role_permissions',
                'shq_roles.position as role_position',
            ]);

        if ($membership === null) {
            return self::none();
        }

        // A role id whose role isn't there grants nothing (fail closed), never the flags.
        if ($membership->shq_role_id !== null && $membership->role_id === null) {
            return new self(false, true, null, []);
        }

        $role = $membership->role_id === null ? null : (new Role)->newFromBuilder([
            'id' => $membership->role_id,
            'account_id' => $membership->role_account_id,
            'key' => $membership->role_key,
            'name' => $membership->role_name,
            'description' => $membership->role_description,
            'permissions' => $membership->role_permissions,
            'position' => $membership->role_position,
        ]);

        return self::forMembership($workspace, (bool) $membership->is_admin, (bool) $membership->requires_approval, $role);
    }

    /**
     * For a membership pivot already loaded (Workspace::members() carries
     * shq_role_id). Pass the company's roles keyed by id when checking many
     * members, so each one costs no query.
     *
     * @param  Collection<string, Role>|null  $roles
     */
    public static function through(User $user, Workspace $workspace, ?object $pivot, ?Collection $roles = null): self
    {
        if ($workspace->account_id !== $user->account_id) {
            return self::none();
        }

        if ($user->ownsAccountOf($workspace)) {
            return self::owner();
        }

        if ($pivot === null) {
            return self::none();
        }

        $roleId = $pivot->shq_role_id ?? null;
        $role = $roleId === null ? null : ($roles !== null ? $roles->get($roleId) : Role::query()->find($roleId));

        // A role id whose role isn't there (or is another company's) grants nothing, as in of().
        if ($roleId !== null && $role === null) {
            return new self(false, true, null, []);
        }

        return self::forMembership($workspace, (bool) ($pivot->is_admin ?? false), (bool) ($pivot->requires_approval ?? false), $role);
    }

    private static function forMembership(Workspace $workspace, bool $isAdmin, bool $flaggedForApproval, ?Role $role): self
    {
        if ($role === null) {
            return new self(false, true, null, self::legacyPermissions($isAdmin, $flaggedForApproval));
        }

        // A role of another company grants nothing (fail closed), but the person stays a member.
        if ($role->account_id !== $workspace->account_id) {
            return new self(false, true, $role, []);
        }

        return new self(false, true, $role, $role->grantedPermissions());
    }

    /**
     * What a membership without a role held before roles existed.
     *
     * @return list<string>
     */
    public static function legacyPermissions(bool $isAdmin, bool $flaggedForApproval): array
    {
        if ($isAdmin) {
            return Permission::all();
        }

        if (ApprovalRules::memberPublishesDirectly($flaggedForApproval)) {
            return [
                Permission::PostsCreate,
                Permission::PostsPublish,
                Permission::PostsApprove,
                Permission::RepurposeManage,
                Permission::PlanEdit,
                Permission::PlanApprove,
                Permission::AiUse,
            ];
        }

        return [Permission::PostsCreate, Permission::PlanEdit, Permission::AiUse];
    }

    private static function owner(): self
    {
        return new self(true, true, null, Permission::all());
    }

    private static function none(): self
    {
        return new self(false, false, null, []);
    }

    public function allows(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /**
     * Whether this person holds every permission in $permissions.
     *
     * @param  list<string>  $permissions
     */
    public function holdsAll(array $permissions): bool
    {
        return array_diff($permissions, $this->permissions) === [];
    }

    /**
     * The role as pages show it: null for the owner and for a membership without a role.
     *
     * @return array{id: string, key: string|null, name: string}|null
     */
    public function roleSummary(): ?array
    {
        if ($this->role === null) {
            return null;
        }

        return [
            'id' => $this->role->id,
            'key' => $this->role->key?->value,
            'name' => $this->role->name,
        ];
    }
}
