<?php

declare(strict_types=1);

namespace App\SocialHq\AiGraphics;

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Ai\AiRouter;
use App\SocialHq\Ai\AiTask;

/**
 * Whether "Generate with AI" is offered, and how much it may be used.
 */
final class AiGraphics
{
    /**
     * On, with an approved image provider that has credentials. When the
     * vision check is on, the vision task needs one too.
     */
    public static function isAvailable(): bool
    {
        if (! config('socialhq.ai_graphics.enabled') || ! AiRouter::isAvailable(AiTask::Image)) {
            return false;
        }

        return ! self::visionCheckEnabled() || AiRouter::isAvailable(AiTask::Vision);
    }

    public static function timeoutSeconds(): int
    {
        return max(30, min(540, (int) config('socialhq.ai_graphics.timeout_seconds', 150)));
    }

    public static function extensionFor(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };
    }

    public static function visionCheckEnabled(): bool
    {
        return (bool) config('socialhq.ai_graphics.vision_check', true);
    }

    /**
     * Whether the workspace still has generations left today. Blocked and
     * failed attempts count too: they cost a vision check or a provider call.
     */
    public static function workspaceHasQuota(Workspace $workspace): bool
    {
        return AiGraphic::query()
            ->where('workspace_id', $workspace->id)
            ->where('created_at', '>=', now()->startOfDay())
            ->count() < (int) config('socialhq.ai_graphics.per_workspace_per_day', 100);
    }

    public static function userHourlyLimit(): int
    {
        return (int) config('socialhq.ai_graphics.per_user_per_hour', 20);
    }

    public static function canUse(User $user, Workspace $workspace): bool
    {
        return self::isAvailable() && $user->can('createPost', $workspace);
    }
}
