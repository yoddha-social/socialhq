<?php

declare(strict_types=1);

namespace App\SocialHq\Events;

/**
 * Where an event is observed: Nepal, worldwide, one other country (its
 * country_code says which) or one brand only.
 */
enum EventRegion: string
{
    case Nepal = 'nepal';
    case International = 'international';
    case Country = 'country';
    case Brand = 'brand';
}
