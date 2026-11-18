<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookLog;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

class WebhookLogPolicy
{
    public function replay(User $user, WebhookLog $webhookLog, Webhook $webhook): bool|Response
    {
        if ($webhookLog->webhook_id !== $webhook->id || $webhook->workspace_id !== $user->current_workspace_id) {
            return false;
        }

        // Social HQ: inspect() keeps manageWebhooks' reason when it refuses an agent.
        return Gate::forUser($user)->inspect('manageWebhooks', $user->currentWorkspace);
    }
}
