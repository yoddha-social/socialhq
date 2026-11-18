<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Webhook;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

class WebhookPolicy
{
    public function viewAny(User $user): bool|Response
    {
        return $user->currentWorkspace === null ? false : $this->manage($user);
    }

    public function view(User $user, Webhook $webhook): bool|Response
    {
        return $webhook->workspace_id !== $user->current_workspace_id ? false : $this->manage($user);
    }

    public function create(User $user): bool|Response
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Webhook $webhook): bool|Response
    {
        return $this->view($user, $webhook);
    }

    public function delete(User $user, Webhook $webhook): bool|Response
    {
        return $this->view($user, $webhook);
    }

    /**
     * Social HQ: inspect() keeps manageWebhooks' reason when it refuses an
     * agent (App\SocialHq\Agents\AdminSettings).
     */
    private function manage(User $user): Response
    {
        return Gate::forUser($user)->inspect('manageWebhooks', $user->currentWorkspace);
    }
}
