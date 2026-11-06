<?php

declare(strict_types=1);

namespace App\SocialHq\Events;

/**
 * Where an event is observed: Nepal, worldwide, or one brand only.
 */
enum EventRegion: string
{
    case Nepal = 'nepal';
    case International = 'international';
    case Brand = 'brand';
}
