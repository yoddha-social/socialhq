<?php

declare(strict_types=1);

namespace App\SocialHq\Approval;

use App\Models\User;
use Laravel\Passport\AccessToken;

/**
 * Social HQ's stricter approval rules. Whether a person publishes directly
 * is their role's "Publish without approval" (App\SocialHq\Access):
 *
 * - members_always_reviewed: for a membership without a role (before roles,
 *   WorkspaceAccess::legacyPermissions()), every member who is not an admin
 *   needs approval, whatever their own flag says. A role decides by itself.
 * - agent_posts_reviewed: a caller using an API key or an MCP grant needs
 *   approval even when the key belongs to an admin, and cannot approve. The
 *   agent wrote the post, not the admin; a person looks at it in the app.
 *
 * TryPost asks User::accessIn() and User::canPublishDirectlyIn() for every
 * post write (web, API, MCP, queue moves, repurpose), so the two hooks there
 * (PATCHES.md) apply these rules everywhere.
 */
final class ApprovalRules
{
    public static function membersAlwaysReviewed(): bool
    {
        return (bool) config('socialhq.approvals.members_always_reviewed', true);
    }

    public static function agentPostsReviewed(): bool
    {
        return (bool) config('socialhq.approvals.agent_posts_reviewed', true);
    }

    /**
     * Whether a user with this access needs approval to schedule or publish:
     * a member whose role can't publish directly, and any agent.
     */
    public static function requiresApproval(User $user, bool $isMember, bool $publishesDirectly): bool
    {
        if (! $isMember) {
            return false;
        }

        if (self::actsAsAgent($user)) {
            return true;
        }

        return ! $publishesDirectly;
    }

    /**
     * Whether a membership without a role, not an admin, publishes directly
     * (and so may approve). Admins always do, outside agent requests.
     */
    public static function memberPublishesDirectly(bool $flagged): bool
    {
        return ! $flagged && ! self::membersAlwaysReviewed();
    }

    /**
     * True when the current request is made by this user through an API key
     * or an MCP grant (a Passport access token), and agent posts are reviewed.
     * Web sessions, queued jobs and console commands are never agents.
     */
    public static function actsAsAgent(User $user): bool
    {
        if (! self::agentPostsReviewed()) {
            return false;
        }

        $caller = auth()->user();

        return $caller instanceof User
            && $caller->is($user)
            && self::usesAccessToken($caller);
    }

    /**
     * Whether this user's current request came through an API key or an MCP
     * grant (a Passport access token), whatever agent_posts_reviewed says.
     */
    public static function usesAccessToken(User $user): bool
    {
        return $user->token() instanceof AccessToken;
    }
}
