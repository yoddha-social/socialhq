<?php

declare(strict_types=1);

namespace App\SocialHq\Events\Nager;

use App\SocialHq\Events\CalendarEvent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reads public holidays from Nager.Date (https://date.nager.at, free, no
 * key; host in `socialhq.events.nager.host`). Answers are cached per
 * (country, year), the country list for a day; failures are not cached.
 *
 * API v3: `GET /api/v3/AvailableCountries` → [{countryCode, name}];
 * `GET /api/v3/PublicHolidays/{year}/{countryCode}` → [{date, localName,
 * name, countryCode, global, counties, types, ...}], 404 for a country it
 * doesn't cover.
 */
final class NagerDate
{
    private const COUNTRIES_KEY = 'socialhq:nager:countries';

    /**
     * The countries Nager.Date covers, by name, without Nepal (our official
     * list covers it).
     *
     * @return list<array{code: string, name: string}>
     *
     * @throws NagerDateUnavailable
     */
    public static function countries(): array
    {
        $cached = Cache::get(self::COUNTRIES_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = self::client()->get('/api/v3/AvailableCountries');
        } catch (Throwable $exception) {
            throw new NagerDateUnavailable(message: $exception->getMessage());
        }

        $items = $response->successful() ? $response->json() : null;

        if (! is_array($items)) {
            throw new NagerDateUnavailable(message: "Nager.Date answered {$response->status()} for the country list.");
        }

        $countries = collect($items)
            ->map(fn (mixed $item): array => [
                'code' => strtoupper(trim((string) data_get($item, 'countryCode'))),
                'name' => trim((string) data_get($item, 'name')),
            ])
            ->filter(fn (array $country): bool => preg_match('/^[A-Z]{2}$/', $country['code']) === 1 && $country['name'] !== '' && $country['code'] !== CalendarEvent::NEPAL)
            ->unique('code')
            ->sortBy('name')
            ->values()
            ->all();

        if ($countries === []) {
            throw new NagerDateUnavailable(message: 'Nager.Date listed no countries.');
        }

        Cache::put(self::COUNTRIES_KEY, $countries, now()->addDay());

        return $countries;
    }

    /**
     * Every country's holidays for every year, read together. Nothing is
     * returned unless all of them could be read.
     *
     * @param  list<string>  $countries  ISO 3166-1 alpha-2 codes
     * @param  list<int>  $years
     * @return array<string, array<int, list<array{date: string, name: string, local_name: string, global: bool, counties: list<string>, types: list<string>}>>>
     *
     * @throws NagerDateUnavailable naming the countries that couldn't be read
     */
    public static function holidays(array $countries, array $years): array
    {
        $holidays = [];
        $missing = [];

        foreach ($countries as $country) {
            foreach ($years as $year) {
                $cached = Cache::get(self::holidaysKey($country, $year));

                if (is_array($cached)) {
                    $holidays[$country][$year] = $cached;
                } else {
                    $missing["{$country}-{$year}"] = [$country, $year];
                }
            }
        }

        if ($missing !== []) {
            $responses = Http::pool(fn (Pool $pool): array => collect($missing)
                ->map(fn (array $pair, string $key) => self::configure($pool->as($key))->get("/api/v3/PublicHolidays/{$pair[1]}/{$pair[0]}"))
                ->values()
                ->all());
            $failed = [];

            foreach ($missing as $key => [$country, $year]) {
                $items = self::items(data_get($responses, $key), $year);

                if ($items === null) {
                    $failed[] = $country;

                    continue;
                }

                $holidays[$country][$year] = $items;
                Cache::put(self::holidaysKey($country, $year), $items, now()->addHours(max(1, (int) config('socialhq.events.nager.cache_hours', 12))));
            }

            if ($failed !== []) {
                throw new NagerDateUnavailable(array_values(array_unique($failed)), 'Nager.Date did not answer for: '.implode(', ', array_unique($failed)).'.');
            }
        }

        return $holidays;
    }

    /**
     * The source link of a country's year: Nager.Date's own answer, so a
     * person can check the date against what was imported.
     */
    public static function sourceUrl(string $country, int $year): string
    {
        return self::host()."/api/v3/PublicHolidays/{$year}/{$country}";
    }

    /**
     * The holidays of one answer, or null when it isn't one. Items without a
     * valid date in the year asked for, or without a name, are left out.
     *
     * @return list<array{date: string, name: string, local_name: string, global: bool, counties: list<string>, types: list<string>}>|null
     */
    private static function items(mixed $response, int $year): ?array
    {
        if (! $response instanceof Response || ! $response->successful()) {
            return null;
        }

        if ($response->status() === 204 || trim($response->body()) === '') {
            return [];
        }

        $items = $response->json();

        if (! is_array($items) || ! array_is_list($items)) {
            return null;
        }

        return collect($items)
            ->map(fn (mixed $item): array => [
                'date' => trim((string) data_get($item, 'date')),
                'name' => trim((string) data_get($item, 'name')),
                'local_name' => trim((string) data_get($item, 'localName')),
                'global' => data_get($item, 'global') !== false,
                'counties' => collect((array) data_get($item, 'counties'))->filter(fn (mixed $county): bool => is_string($county) && $county !== '')->values()->all(),
                'types' => collect((array) data_get($item, 'types'))->filter(fn (mixed $type): bool => is_string($type) && $type !== '')->values()->all(),
            ])
            ->filter(fn (array $item): bool => $item['name'] !== '' && self::isDateIn($item['date'], $year))
            ->values()
            ->all();
    }

    private static function isDateIn(string $date, int $year): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return false;
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== null && $parsed->format('Y-m-d') === $date && $parsed->year === $year;
    }

    private static function holidaysKey(string $country, int $year): string
    {
        return "socialhq:nager:holidays:{$country}:{$year}";
    }

    private static function client(): PendingRequest
    {
        return self::configure(Http::createPendingRequest());
    }

    private static function configure(PendingRequest $request): PendingRequest
    {
        return $request->baseUrl(self::host())
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(max(1, (int) config('socialhq.events.nager.timeout_seconds', 10)));
    }

    private static function host(): string
    {
        return rtrim((string) config('socialhq.events.nager.host'), '/');
    }
}
