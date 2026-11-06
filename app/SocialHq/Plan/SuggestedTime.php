<?php

declare(strict_types=1);

namespace App\SocialHq\Plan;

use Carbon\CarbonImmutable;
use DateTimeZone;

/**
 * An item's posting time: a wall clock ("HH:MM", 24-hour) on its day in its
 * own zone (the main audience zone when it was set), and the instant that
 * makes. The AI suggests the clock; the zone always comes from the code.
 */
final class SuggestedTime
{
    /** What a stored or submitted time looks like. */
    public const PATTERN = '/^([01]\d|2[0-3]):[0-5]\d$/';

    /**
     * "9:00" or "09:00" as "09:00"; anything else (empty, "25:00", "9am") as null.
     */
    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\s*(\d{1,2}):(\d{2})\s*$/', $value, $parts) !== 1) {
            return null;
        }

        [$hour, $minute] = [(int) $parts[1], (int) $parts[2]];

        return $hour <= 23 && $minute <= 59 ? sprintf('%02d:%02d', $hour, $minute) : null;
    }

    public static function isZone(mixed $value): bool
    {
        return is_string($value) && in_array($value, DateTimeZone::listIdentifiers(), true);
    }

    /**
     * The UTC instant of the item's time, or null without one. A clock that
     * falls in a daylight saving gap moves forward, as PHP does.
     */
    public static function instant(PlanItem $item): ?CarbonImmutable
    {
        $time = self::normalize($item->suggested_time);

        if ($time === null || ! self::isZone($item->suggested_timezone)) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d H:i', "{$item->scheduled_on->toDateString()} {$time}", $item->suggested_timezone)->utc();
    }

    /**
     * The item's time as a wall clock ("YYYY-MM-DDTHH:MM") in another zone,
     * as the composer takes it (in the user's zone).
     */
    public static function wallClockIn(PlanItem $item, string $zone): ?string
    {
        return self::instant($item)?->setTimezone($zone)->format('Y-m-d\TH:i');
    }
}
