<?php

declare(strict_types=1);

namespace App\SocialHq\AiGraphics;

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Access\AiAccess;
use App\SocialHq\Ai\AiRouter;
use App\SocialHq\Ai\AiTask;

/**
 * Whether "Generate with AI" is offered, and how much it may be used.
 */
final class AiGraphics
{
    /** The company's AI can't make images. */
    public const MISSING_IMAGE = 'image';

    /** The company's AI can't read images, which the content check needs. */
    public const MISSING_VISION = 'vision';

    /** Neither. */
    public const MISSING_IMAGE_VISION = 'image_vision';

    /**
     * On, with an approved image provider that has credentials. When the
     * vision check is on, the vision task needs one too.
     */
    public static function isAvailable(): bool
    {
        return config('socialhq.ai_graphics.enabled') && self::missing() === null;
    }

    /**
     * What the company's AI lacks for graphics (one of the MISSING_
     * constants), or null when it can make them.
     */
    public static function missing(): ?string
    {
        $image = AiRouter::isAvailable(AiTask::Image);
        $vision = ! self::visionCheckEnabled() || AiRouter::isAvailable(AiTask::Vision);

        return match (true) {
            $image && $vision => null,
            $vision => self::MISSING_IMAGE,
            $image => self::MISSING_VISION,
            default => self::MISSING_IMAGE_VISION,
        };
    }

    /**
     * Whether "Generate with AI" shows at all: on, the person may create
     * posts here, and the company has some AI. When that AI can't make
     * graphics, the button says what is missing (missing()) instead of
     * hiding, so people know graphics exist and who can turn them on.
     */
    public static function isOffered(User $user, Workspace $workspace): bool
    {
        return config('socialhq.ai_graphics.enabled')
            && self::mayMakeGraphics($user, $workspace)
            && AiRouter::anyAvailable();
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
        return self::isAvailable() && self::mayMakeGraphics($user, $workspace);
    }

    /**
     * Social HQ roles: "Use AI" and "Write posts" (the graphic arrives through
     * the media import, which GenerateAiGraphic and ImportRemoteMedia check
     * with createPost).
     */
    public static function mayMakeGraphics(User $user, Workspace $workspace): bool
    {
        return AiAccess::allows($user, $workspace) && $user->can('createPost', $workspace);
    }
}
