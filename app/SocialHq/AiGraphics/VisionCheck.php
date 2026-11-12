<?php

declare(strict_types=1);

namespace App\SocialHq\AiGraphics;

use Illuminate\Support\Str;
use Laravel\Ai\Files\Image;
use Throwable;

/**
 * Runs GraphicSafetyReviewer. Fails closed: an error, a timeout or an answer
 * that is not a clear yes blocks the graphic.
 */
final class VisionCheck
{
    /**
     * @param  list<Image>  $images
     * @param  bool  $brandLogo  The first reference image is the brand's own logo (Brand look)
     * @return string|null null when allowed, the reason otherwise
     */
    public static function review(string $prompt, array $images, bool $reviewsResult = false, bool $brandLogo = false): ?string
    {
        if (! AiGraphics::visionCheckEnabled()) {
            return null;
        }

        try {
            $response = (new GraphicSafetyReviewer($reviewsResult, $brandLogo))->prompt(
                "Prompt:\n".$prompt,
                attachments: $images,
                timeout: 60,
            );
        } catch (Throwable $exception) {
            report($exception);

            return __('socialhq.ai_graphics.errors.check_unavailable');
        }

        if ($response['allowed'] ?? false) {
            return null;
        }

        return Str::limit(trim((string) ($response['reason'] ?? '')), 480) ?: __('socialhq.ai_graphics.errors.blocked');
    }
}
