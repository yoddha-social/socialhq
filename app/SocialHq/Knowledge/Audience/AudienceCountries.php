<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Audience;

use DateTimeZone;
use Locale as IntlLocale;

/**
 * The countries an audience can be in (ISO 3166-1 alpha-2, from the time
 * zone database), their English names and each one's main time zone.
 */
final class AudienceCountries
{
    /**
     * Countries with several zones, where the first one alphabetically is not
     * where most people live (America/Adak for the US). Others take their
     * first zone, which for most countries is their only one.
     */
    private const MAIN_ZONES = [
        'AR' => 'America/Argentina/Buenos_Aires',
        'AU' => 'Australia/Sydney',
        'BR' => 'America/Sao_Paulo',
        'CA' => 'America/Toronto',
        'CD' => 'Africa/Kinshasa',
        'CL' => 'America/Santiago',
        'CN' => 'Asia/Shanghai',
        'CY' => 'Asia/Nicosia',
        'DE' => 'Europe/Berlin',
        'EC' => 'America/Guayaquil',
        'ES' => 'Europe/Madrid',
        'FM' => 'Pacific/Pohnpei',
        'GL' => 'America/Nuuk',
        'ID' => 'Asia/Jakarta',
        'KI' => 'Pacific/Tarawa',
        'KZ' => 'Asia/Almaty',
        'MN' => 'Asia/Ulaanbaatar',
        'MX' => 'America/Mexico_City',
        'MY' => 'Asia/Kuala_Lumpur',
        'NZ' => 'Pacific/Auckland',
        'PF' => 'Pacific/Tahiti',
        'PG' => 'Pacific/Port_Moresby',
        'PT' => 'Europe/Lisbon',
        'RU' => 'Europe/Moscow',
        'UA' => 'Europe/Kyiv',
        'US' => 'America/New_York',
        'UZ' => 'Asia/Tashkent',
    ];

    /** @var array<string, list<string>>|null zones by country code */
    private static ?array $zones = null;

    /**
     * Every country, by name.
     *
     * @return list<array{code: string, name: string, timezone: string}>
     */
    public static function all(): array
    {
        return collect(array_keys(self::zones()))
            ->map(fn (string $code): array => ['code' => $code, 'name' => self::name($code), 'timezone' => self::mainZone($code)])
            ->sortBy('name')
            ->values()
            ->all();
    }

    public static function exists(string $code): bool
    {
        return array_key_exists($code, self::zones());
    }

    public static function name(string $code): string
    {
        $name = IntlLocale::getDisplayRegion("und_{$code}", 'en');

        return is_string($name) && $name !== '' ? $name : $code;
    }

    public static function mainZone(string $code): string
    {
        $zones = self::zones()[$code] ?? [];
        $main = self::MAIN_ZONES[$code] ?? null;

        return $main !== null && in_array($main, $zones, true) ? $main : ($zones[0] ?? 'UTC');
    }

    /**
     * @return array<string, list<string>>
     */
    private static function zones(): array
    {
        if (self::$zones !== null) {
            return self::$zones;
        }

        $zones = [];

        foreach (DateTimeZone::listIdentifiers() as $identifier) {
            $location = (new DateTimeZone($identifier))->getLocation();
            $code = (string) data_get(is_array($location) ? $location : [], 'country_code', '??');

            if (preg_match('/^[A-Z]{2}$/', $code) === 1) {
                $zones[$code][] = $identifier;
            }
        }

        ksort($zones);

        return self::$zones = $zones;
    }
}
