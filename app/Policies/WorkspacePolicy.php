<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Agents\AdminSettings;
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
        return $this->isAdmin($user, $workspace);
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
        return $this->isAdmin($user, $workspace);
    }

    public function manageAccounts(User $user, Workspace $workspace): bool
    {
        return $this->isAdmin($user, $workspace);
    }

    /**
     * Social HQ: an API key or MCP grant never manages webhooks (AdminSettings).
     */
    public function manageWebhooks(User $user, Workspace $workspace): bool|Response
    {
        return AdminSettings::refusalFor($user) ?? $this->isAdmin($user, $workspace);
    }

    /**
     * Social HQ: managing API keys is manageTeam without agents (AdminSettings).
     * manageTeam itself stays as it is: every API key request needs it
     * (LoadWorkspaceFromToken).
     */
    public function manageApiKeys(User $user, Workspace $workspace): bool|Response
    {
        return AdminSettings::refusalFor($user) ?? $this->manageTeam($user, $workspace);
    }

    public function manageRepurposes(User $user, Workspace $workspace): bool
    {
        return $this->publishDirectly($user, $workspace);
    }

    /**
     * Social HQ: an agent (API key or MCP grant) of someone who manages
     * repurposes may read them; changing one stays with manageRepurposes,
     * because a repurpose's posts publish without review.
     */
    public function viewRepurposes(User $user, Workspace $workspace): bool
    {
        return $this->canAccess($user, $workspace) && $user->publishesDirectlyAsPersonIn($workspace);
    }

    /**
     * Every member may write posts; whether they reach the queue directly is
     * decided by `publishDirectly`.
     */
    public function createPost(User $user, Workspace $workspace): bool
    {
        return $this->canAccess($user, $workspace);
    }

    public function publishDirectly(User $user, Workspace $workspace): bool
    {
        return $this->canAccess($user, $workspace) && $user->canPublishDirectlyIn($workspace);
    }

    public function approvePosts(User $user, Workspace $workspace): bool
    {
        return $this->publishDirectly($user, $workspace);
    }

    public function inviteMember(User $user, Workspace $workspace): bool
    {
        return $this->isAdmin($user, $workspace);
    }

    public function manageBilling(User $user, Workspace $workspace): bool
    {
        return $this->isOwner($user, $workspace);
    }

    private function isOwner(User $user, Workspace $workspace): bool
    {
        return $user->ownsAccountOf($workspace);
    }

    private function isAdmin(User $user, Workspace $workspace): bool
    {
        return $user->isWorkspaceAdmin($workspace);
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
