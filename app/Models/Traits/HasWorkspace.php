<?php

declare(strict_types=1);

namespace App\Models\Traits;

use App\Models\Workspace;
use App\SocialHq\Access\Permission;
use App\SocialHq\Access\WorkspaceAccess;
use App\SocialHq\Approval\ApprovalRules;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

trait HasWorkspace
{
    /**
     * Get all workspaces the user belongs to (as owner or member).
     */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'user_workspace')
            ->withTimestamps();
    }

    /**
     * Get the user's current workspace.
     */
    public function currentWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'current_workspace_id');
    }

    /**
     * Switch to a different workspace.
     */
    public function switchWorkspace(Workspace $workspace): void
    {
        $this->update(['current_workspace_id' => $workspace->id]);
        $this->setRelation('currentWorkspace', $workspace);
    }

    /**
     * Check if user belongs to a workspace on their current account.
     */
    public function belongsToWorkspace(Workspace $workspace): bool
    {
        if ($workspace->account_id !== $this->account_id) {
            return false;
        }

        return $this->workspaces()->where('workspaces.id', $workspace->id)->exists();
    }

    /**
     * Workspaces the user can use on their current account (never cross-account).
     *
     * @return BelongsToMany<Workspace, $this>
     */
    public function accountWorkspaces(): BelongsToMany
    {
        return $this->workspaces()
            ->where('workspaces.account_id', $this->account_id);
    }

    /**
     * Get the count of workspaces the user owns.
     */
    public function ownedWorkspacesCount(): int
    {
        return Workspace::where('user_id', $this->id)->count();
    }

    /**
     * The workspace limit never applies self-hosted, nor to the first workspace.
     */
    public function workspaceLimitApplies(): bool
    {
        return ! config('trypost.self_hosted') && $this->ownedWorkspacesCount() > 0;
    }

    /**
     * The account already holds as many workspaces as its plan allows.
     */
    public function atWorkspaceLimit(): bool
    {
        return $this->workspaceLimitApplies() && $this->account?->canCreateWorkspace() === false;
    }

    /**
     * The account owner always manages every workspace of the account.
     */
    public function ownsAccountOf(Workspace $workspace): bool
    {
        return $workspace->account_id === $this->account_id && $this->isAccountOwner();
    }

    /**
     * The user's access to the workspace from one membership read.
     *
     * Social HQ: what a person may do comes from their role
     * (App\SocialHq\Access\WorkspaceAccess): the owner holds every
     * permission, a member their role's. `is_admin` is "may manage members"
     * and `requires_approval` "may not publish directly" (always for agents).
     * Users outside the workspace get no access and are never gated.
     *
     * @return array{is_owner: bool, is_admin: bool, requires_approval: bool, role: array{id: string, key: string|null, name: string}|null, permissions: list<string>}
     */
    public function accessIn(Workspace $workspace): array
    {
        $access = WorkspaceAccess::of($this, $workspace);

        return [
            'is_owner' => $access->isOwner,
            'is_admin' => $access->allows(Permission::TeamManage),
            'requires_approval' => ApprovalRules::requiresApproval($this, $access->isMember, $access->allows(Permission::PostsPublish)),
            'role' => $access->roleSummary(),
            'permissions' => $access->permissions,
        ];
    }

    /**
     * Social HQ: what this person's role allows in the workspace.
     */
    public function hasPermissionIn(Workspace $workspace, string $permission): bool
    {
        return WorkspaceAccess::of($this, $workspace)->allows($permission);
    }

    public function isWorkspaceAdmin(Workspace $workspace): bool
    {
        return $this->accessIn($workspace)['is_admin'];
    }

    public function requiresApprovalIn(Workspace $workspace): bool
    {
        return $this->accessIn($workspace)['requires_approval'];
    }

    public function canPublishDirectlyIn(Workspace $workspace): bool
    {
        return ! ApprovalRules::actsAsAgent($this) && $this->publishesDirectlyAsPersonIn($workspace);
    }

    /**
     * Whether this user publishes directly when signed in to the app, setting
     * aside the agent rule: what the person behind an API key may see.
     */
    public function publishesDirectlyAsPersonIn(Workspace $workspace): bool
    {
        return $this->hasPermissionIn($workspace, Permission::PostsPublish);
    }

    /**
     * The direct-publishing rule for an already-loaded membership pivot
     * (Social HQ: its role, through WorkspaceAccess::through()).
     */
    public function publishesDirectlyThrough(Workspace $workspace, ?Pivot $pivot): bool
    {
        return WorkspaceAccess::through($this, $workspace, $pivot)->allows(Permission::PostsPublish);
    }
}
