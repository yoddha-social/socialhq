<?php

declare(strict_types=1);

namespace App\SocialHq\Events;

/**
 * What kind of date an event is. Decides its colour and how the planner treats it.
 */
enum EventCategory: string
{
    case Festival = 'festival';
    case PublicHoliday = 'public_holiday';
    case NationalDay = 'national_day';
    case InternationalDay = 'international_day';
    case Commercial = 'commercial';
    case Observance = 'observance';
    case Brand = 'brand';
}
