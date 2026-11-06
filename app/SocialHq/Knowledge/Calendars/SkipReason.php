<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Calendars;

/**
 * Why a row of an old calendar was not imported. The page shows
 * socialhq_calendars.skipped.reasons.<value>.
 */
enum SkipReason: string
{
    case Empty = 'empty';
    case NoDate = 'no_date';
    case UnreadableDate = 'unreadable_date';
    case BikramSambat = 'bikram_sambat';
    case FutureDate = 'future_date';
}
