<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Access\Permission;
use App\SocialHq\Access\WorkspaceAccess;
use App\SocialHq\Agents\AdminSettings;
use App\SocialHq\Approval\ApprovalRules;
use App\SocialHq\Companies\CompanyAccess;
use Illuminate\Auth\Access\Response;

class WorkspacePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Workspace $workspace): bool
    {
        return $this->canAccess($user, $workspace);
    }

    public function create(User $user): bool
    {
        // Social HQ: only an approved company's owner creates workspaces (self-hosted).
        return $user->isAccountOwner() && CompanyAccess::mayCreateWorkspaces($user);
    }

    public function update(User $user, Workspace $workspace): bool
    {
        return $this->allows($user, $workspace, Permission::WorkspaceSettings);
    }

    public function delete(User $user, Workspace $workspace): bool
    {
        return $this->isOwner($user, $workspace);
    }

    public function restore(User $user, Workspace $workspace): bool
    {
        return $this->isOwner($user, $workspace);
    }

    public function forceDelete(User $user, Workspace $workspace): bool
    {
        return $this->isOwner($user, $workspace);
    }

    public function manageTeam(User $user, Workspace $workspace): bool
    {
        return $this->allows($user, $workspace, Permission::TeamManage);
    }

    public function manageAccounts(User $user, Workspace $workspace): bool
    {
        return $this->allows($user, $workspace, Permission::ChannelsManage);
    }

    /**
     * Social HQ: an API key or MCP grant never manages webhooks (AdminSettings).
     */
    public function manageWebhooks(User $user, Workspace $workspace): bool|Response
    {
        return AdminSettings::refusalFor($user) ?? $this->allows($user, $workspace, Permission::WebhooksManage);
    }

    /**
     * Social HQ: managing API keys is the role's "Manage API keys", never for
     * agents (AdminSettings).
     */
    public function manageApiKeys(User $user, Workspace $workspace): bool|Response
    {
        return AdminSettings::refusalFor($user) ?? $this->useApiKeys($user, $workspace);
    }

    /**
     * Social HQ: every REST API key request needs it (LoadWorkspaceFromToken),
     * so a key stops working when its person's role loses "Manage API keys".
     */
    public function useApiKeys(User $user, Workspace $workspace): bool
    {
        return $this->allows($user, $workspace, Permission::ApiKeysManage);
    }

    public function manageRepurposes(User $user, Workspace $workspace): bool
    {
        return $this->publishDirectly($user, $workspace) && $this->allows($user, $workspace, Permission::RepurposeManage);
    }

    /**
     * Social HQ: an agent (API key or MCP grant) of someone who manages
     * repurposes may read them; changing one stays with manageRepurposes,
     * because a repurpose's posts publish without review.
     */
    public function viewRepurposes(User $user, Workspace $workspace): bool
    {
        return $this->allows($user, $workspace, Permission::RepurposeManage);
    }

    /**
     * Social HQ: members whose role has "Write posts"; whether they reach the
     * queue directly is decided by `publishDirectly`.
     */
    public function createPost(User $user, Workspace $workspace): bool
    {
        return $this->allows($user, $workspace, Permission::PostsCreate);
    }

    public function publishDirectly(User $user, Workspace $workspace): bool
    {
        return $this->canAccess($user, $workspace) && $user->canPublishDirectlyIn($workspace);
    }

    /**
     * Social HQ: the role's "Approve posts", never through an agent.
     */
    public function approvePosts(User $user, Workspace $workspace): bool
    {
        return $this->allows($user, $workspace, Permission::PostsApprove) && ! ApprovalRules::actsAsAgent($user);
    }

    public function inviteMember(User $user, Workspace $workspace): bool
    {
        return $this->allows($user, $workspace, Permission::TeamManage);
    }

    public function manageBilling(User $user, Workspace $workspace): bool
    {
        return $this->isOwner($user, $workspace);
    }

    private function isOwner(User $user, Workspace $workspace): bool
    {
        return $user->ownsAccountOf($workspace);
    }

    /**
     * Social HQ: what the user's role grants here (App\SocialHq\Access).
     */
    private function allows(User $user, Workspace $workspace, string $permission): bool
    {
        return WorkspaceAccess::of($user, $workspace)->allows($permission);
    }

    private function canAccess(User $user, Workspace $workspace): bool
    {
        if ($workspace->account_id !== $user->account_id) {
            return false;
        }

        if ($user->isAccountOwner()) {
            return true;
        }

        return $workspace->members()->where('user_id', $user->id)->exists();
    }
}
