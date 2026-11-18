<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Repurpose;
use App\Models\User;
use App\SocialHq\Approval\ApprovalRules;
use Illuminate\Auth\Access\Response;

class RepurposePolicy
{
    /**
     * Social HQ: seeing repurposes follows viewRepurposes, so an API key or MCP
     * grant of someone who manages them may read them (see manage()).
     */
    public function viewAny(User $user): bool
    {
        return $user->currentWorkspace !== null
            && $user->can('viewRepurposes', $user->currentWorkspace);
    }

    /**
     * A repurpose of another workspace is denied as not found, so its
     * existence does not leak across tenants.
     */
    public function view(User $user, Repurpose $repurpose): bool|Response
    {
        if ($repurpose->workspace_id !== $user->current_workspace_id) {
            return Response::denyAsNotFound();
        }

        return $user->can('viewRepurposes', $user->currentWorkspace);
    }

    public function create(User $user): bool|Response
    {
        return $user->currentWorkspace === null ? false : $this->manage($user);
    }

    public function update(User $user, Repurpose $repurpose): bool|Response
    {
        return $this->viewThenManage($user, $repurpose);
    }

    public function delete(User $user, Repurpose $repurpose): bool|Response
    {
        return $this->viewThenManage($user, $repurpose);
    }

    private function viewThenManage(User $user, Repurpose $repurpose): bool|Response
    {
        $view = $this->view($user, $repurpose);

        return $view === true ? $this->manage($user) : $view;
    }

    /**
     * Social HQ: an agent never creates or changes a repurpose. Its posts are
     * made later by a queued job acting as the person, so they skip the review
     * every agent post gets (ApprovalRules).
     */
    private function manage(User $user): bool|Response
    {
        if (ApprovalRules::actsAsAgent($user)) {
            return Response::deny(__('socialhq.agents.repurposes_read_only'));
        }

        return $user->can('manageRepurposes', $user->currentWorkspace);
    }
}
