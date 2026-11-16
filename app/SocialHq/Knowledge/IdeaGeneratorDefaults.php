<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge;

use App\Models\Workspace;
use Illuminate\Support\Str;

/**
 * What the idea generator ("Generate ideas" on Ideas) starts with: the
 * brand profile's "what the brand is" and audience, so nobody types them
 * again. The person can still change them before generating. One small
 * read of the workspace's profile row.
 */
final class IdeaGeneratorDefaults
{
    /** The generator's own limit for each field (GenerateIdeaRequest). */
    public const MAX_LENGTH = 1000;

    /**
     * @return array{business: string, audience: string}
     */
    public static function for(Workspace $workspace): array
    {
        $profile = BrandProfile::query()
            ->where('workspace_id', $workspace->id)
            ->first(['about', 'audience']);

        return [
            'business' => self::fit($profile?->about),
            'audience' => self::fit($profile?->audience),
        ];
    }

    private static function fit(?string $text): string
    {
        $text = trim((string) $text);

        return mb_strlen($text) > self::MAX_LENGTH
            ? rtrim(Str::substr($text, 0, self::MAX_LENGTH - 1)).'…'
            : $text;
    }
}
