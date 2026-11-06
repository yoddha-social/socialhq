<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Calendars;

/**
 * How to read a date written with slashes or dots: 03/10/2025 is 3 October
 * day first, 10 March month first. Dates written as 2025-10-03, or with the
 * month's name, read the same either way.
 */
enum DateOrder: string
{
    case DayFirst = 'day_first';
    case MonthFirst = 'month_first';
}
