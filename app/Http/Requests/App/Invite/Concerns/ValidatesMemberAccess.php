<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Invite\Concerns;

use App\Models\Workspace;
use App\SocialHq\Access\Role;
use App\SocialHq\Access\Roles;
use App\SocialHq\Approval\ApprovalRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Social HQ: a member's access is a role (`role_id`, a role of the current
 * workspace's company). The flags TryPost's dialogs send (`is_admin`,
 * `requires_approval`) still work without one and pick the matching built-in
 * role (Roles::forFlags).
 */
trait ValidatesMemberAccess
{
    /**
     * @return array<string, array<int, ValidationRule|Exists|string>>
     */
    protected function memberAccessRules(): array
    {
        $accountId = $this->user()?->currentWorkspace?->account_id;

        return [
            'role_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('shq_roles', 'id')->where('account_id', $accountId)],
            // Without a role, both flags, as TryPost's dialogs send them.
            'is_admin' => ['required_without:role_id', 'boolean'],
            'requires_approval' => ['required_without:role_id', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function memberAccessMessages(): array
    {
        return [
            'role_id.uuid' => __('socialhq_roles.errors.role_missing'),
            'role_id.exists' => __('socialhq_roles.errors.role_missing'),
        ];
    }

    /**
     * The flags TryPost's dialogs send: an admin never needs approval.
     *
     * @return array{is_admin: bool, requires_approval: bool}
     */
    public function memberAccess(): array
    {
        $isAdmin = $this->boolean('is_admin');

        return [
            'is_admin' => $isAdmin,
            'requires_approval' => ! $isAdmin && ($this->boolean('requires_approval') || ApprovalRules::membersAlwaysReviewed()),
        ];
    }

    /**
     * The role picked: `role_id`, else the built-in role matching the flags.
     */
    public function memberRole(Workspace $workspace): Role
    {
        $roleId = $this->validated('role_id');

        if (filled($roleId)) {
            return Role::query()->forAccount($workspace->account_id)->findOrFail($roleId);
        }

        $access = $this->memberAccess();

        return Roles::forFlags($workspace->account_id, $access['is_admin'], $access['requires_approval']);
    }
}
