<?php

declare(strict_types=1);

namespace App\SocialHq\Approval;

use App\Models\User;
use Laravel\Passport\AccessToken;

/**
 * Social HQ's stricter approval rules, on top of TryPost's per-member flag:
 *
 * - members_always_reviewed: every member who is not an admin needs approval,
 *   whatever their own flag says; only admins (and the owner) approve.
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
     * Whether a user with this access needs approval to schedule or publish.
     */
    public static function requiresApproval(User $user, bool $isMember, bool $isAdmin, bool $flagged): bool
    {
        if (! $isMember) {
            return false;
        }

        if (self::actsAsAgent($user)) {
            return true;
        }

        return ! $isAdmin && ($flagged || self::membersAlwaysReviewed());
    }

    /**
     * Whether a member who is not an admin publishes directly (and so may
     * approve). Admins always do, outside agent requests.
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
            && $caller->token() instanceof AccessToken;
    }
}
