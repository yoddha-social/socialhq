<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Look;

use App\Models\Workspace;
use App\SocialHq\Http\Requests\StoreBrandLogoRequest;

/**
 * The `look` prop of the Brand knowledge page.
 */
final class LookPage
{
    /**
     * @return array<string, mixed>
     */
    public static function prop(Workspace $workspace): array
    {
        $look = BrandLook::of($workspace);

        return [
            'logo' => $look?->hasLogo() ? [
                // The version makes a replaced logo show at once despite the browser cache.
                'url' => route('socialhq.brand.look.logo.show', ['v' => $look->updated_at?->timestamp]),
                'mime' => $look->logo_mime,
                'isVector' => $look->logo_mime === 'image/svg+xml',
                'width' => $look->logo_width,
                'height' => $look->logo_height,
            ] : null,
            'colours' => $look?->colourList() ?? [],
            'heading_font' => (string) $look?->heading_font,
            'body_font' => (string) $look?->body_font,
            'style_notes' => (string) $look?->style_notes,
            'maxColours' => BrandLook::MAX_COLOURS,
            'maxLogoKilobytes' => StoreBrandLogoRequest::maxKilobytes(),
        ];
    }
}
