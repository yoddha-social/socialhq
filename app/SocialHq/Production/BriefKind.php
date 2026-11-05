<?php

declare(strict_types=1);

namespace App\SocialHq\Production;

/**
 * What a brief asks the creative team for.
 */
enum BriefKind: string
{
    case Graphic = 'graphic';
    case Video = 'video';
}
