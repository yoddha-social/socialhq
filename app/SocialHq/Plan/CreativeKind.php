<?php

declare(strict_types=1);

namespace App\SocialHq\Plan;

use App\SocialHq\Production\BriefKind;

/**
 * What the item needs made: nothing, an image, a carousel, a video or a document.
 */
enum CreativeKind: string
{
    case None = 'none';
    case Image = 'image';
    case Carousel = 'carousel';
    case Video = 'video';
    case Document = 'document';

    /**
     * The brief this creative needs, or null when nothing is made.
     */
    public function briefKind(): ?BriefKind
    {
        return match ($this) {
            self::None => null,
            self::Video => BriefKind::Video,
            default => BriefKind::Graphic,
        };
    }
}
