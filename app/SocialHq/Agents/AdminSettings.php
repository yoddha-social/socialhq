<?php

declare(strict_types=1);

namespace App\SocialHq\Agents;

use App\Models\User;
use App\SocialHq\Approval\ApprovalRules;
use Illuminate\Auth\Access\Response;

/**
 * API keys and webhooks are managed by a person in the app, never by an agent
 * (an API key or MCP grant), even one with an admin's rights. An agent that
 * reads untrusted text (a web page, a comment, an email) can be steered by it:
 * a new API key outlives the conversation, and a webhook sends every post
 * event to whatever URL it names.
 *
 * WorkspacePolicy::manageApiKeys() and manageWebhooks() ask refusalFor(); the
 * MCP server hides the tools (App\SocialHq\Mcp\HiddenTools). The switch is
 * socialhq.agents.manage_admin_settings (off; phpunit.xml turns it on for
 * upstream's tests).
 */
final class AdminSettings
{
    public static function agentsMayManage(): bool
    {
        return (bool) config('socialhq.agents.manage_admin_settings', false);
    }

    /**
     * The refusal for a caller using an API key or MCP grant, or null when
     * the caller may go on to the usual admin check.
     */
    public static function refusalFor(User $user): ?Response
    {
        if (self::agentsMayManage() || ! ApprovalRules::usesAccessToken($user)) {
            return null;
        }

        return Response::deny(__('socialhq.agents.admin_settings'));
    }
}
