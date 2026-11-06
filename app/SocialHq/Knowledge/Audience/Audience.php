<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Audience;

use App\SocialHq\Knowledge\BrandProfile;
use App\Support\Timezone;
use DateTimeZone;

/**
 * Where a brand's audience is, from its profile: up to MAX_LOCATIONS places
 * (a country, maybe a city, a time zone and a share of the audience, the
 * shares adding up to 100 at most), largest share first. The main zone, the
 * largest share's, is the zone plan items get their posting time in.
 */
final class Audience
{
    public const MAX_LOCATIONS = 10;

    public const MAX_CITY_LENGTH = 80;

    /**
     * @return list<array{country: string, city: string|null, timezone: string, share: int}>
     */
    public static function locations(?BrandProfile $profile): array
    {
        $zones = DateTimeZone::listIdentifiers();

        return collect((array) ($profile?->audience_locations ?? []))
            ->map(fn (mixed $row): array => [
                'country' => strtoupper(trim((string) data_get($row, 'country'))),
                'city' => filled(data_get($row, 'city')) ? trim((string) data_get($row, 'city')) : null,
                'timezone' => (string) data_get($row, 'timezone'),
                'share' => (int) data_get($row, 'share'),
            ])
            ->filter(fn (array $row): bool => AudienceCountries::exists($row['country']) && in_array($row['timezone'], $zones, true) && $row['share'] > 0)
            ->sortByDesc('share')
            ->take(self::MAX_LOCATIONS)
            ->values()
            ->all();
    }

    /**
     * The largest share's zone, else the fallback (the zone of the person the
     * plan is made for), else UTC.
     */
    public static function mainZone(?BrandProfile $profile, ?string $fallback = null): string
    {
        return data_get(self::locations($profile), '0.timezone') ?? Timezone::normalize($fallback);
    }

    /**
     * The audience's countries, largest share first.
     *
     * @return list<string>
     */
    public static function countries(?BrandProfile $profile): array
    {
        return collect(self::locations($profile))->pluck('country')->unique()->values()->all();
    }

    /**
     * What the planner is told about the audience.
     *
     * @return array{main_timezone: string, locations: list<array{country: string, city: string|null, timezone: string, share_percent: int}>}
     */
    public static function forPlanner(?BrandProfile $profile, string $mainZone): array
    {
        return [
            'main_timezone' => $mainZone,
            'locations' => array_map(fn (array $row): array => [
                'country' => AudienceCountries::name($row['country']),
                'city' => $row['city'],
                'timezone' => $row['timezone'],
                'share_percent' => $row['share'],
            ], self::locations($profile)),
        ];
    }
}
