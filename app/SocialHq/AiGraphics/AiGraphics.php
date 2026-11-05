<?php

declare(strict_types=1);

namespace App\SocialHq\AiGraphics;

use App\Models\User;
use App\Models\Workspace;

/**
 * Whether "Generate with AI" is offered, and how much it may be used.
 */
final class AiGraphics
{
    public static function provider(): string
    {
        return (string) config('socialhq.ai_graphics.provider', 'gemini');
    }

    public static function model(): ?string
    {
        return config('socialhq.ai_graphics.model') ?: null;
    }

    /**
     * On, with an image-capable provider that has a key. When the vision
     * check is on, the text provider that runs it needs a key too.
     */
    public static function isAvailable(): bool
    {
        if (! config('socialhq.ai_graphics.enabled')
            || ! in_array(self::provider(), ['gemini', 'openai', 'xai'], true)
            || blank(config('ai.providers.'.self::provider().'.key'))) {
            return false;
        }

        return ! self::visionCheckEnabled() || filled(config('ai.providers.'.self::visionProvider().'.key'));
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

    public static function visionProvider(): string
    {
        return (string) (config('socialhq.ai_graphics.vision_provider') ?: config('ai.default'));
    }

    public static function visionModel(): ?string
    {
        return config('socialhq.ai_graphics.vision_model') ?: null;
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
