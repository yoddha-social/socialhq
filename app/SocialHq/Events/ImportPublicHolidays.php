<?php

declare(strict_types=1);

namespace App\SocialHq\Events;

use App\Models\Account;
use App\SocialHq\Events\Nager\NagerDate;
use App\SocialHq\Events\Nager\NagerDateUnavailable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Loads countries' public holidays from Nager.Date into an account, as
 * account-wide dates a person still has to confirm ("Needs check"). Nepal is
 * refused: its dates come from our official list (ImportOfficialEvents).
 *
 * Each row remembers its holiday (import_key + import_date) and is matched
 * on that within its country, so re-importing follows WriteImportedEvent's
 * rules: an unchanged row keeps its confirmation, and a row a person added,
 * edited, narrowed to one brand or deleted is never overwritten or brought
 * back. Holidays Nager.Date no longer lists for an imported year are
 * counted, not deleted. Nothing is written unless every country and year
 * could be read.
 */
final class ImportPublicHolidays
{
    public const SOURCE_NAME = 'Nager.Date';

    /** Who else a non-public day is off for, by Nager.Date's type. */
    private const DAY_OFF_FOR = [
        'Bank' => 'banks',
        'School' => 'schools',
        'Authorities' => 'government offices',
    ];

    /**
     * @param  list<string>  $countries  ISO 3166-1 alpha-2 codes
     * @param  list<int>  $years
     * @return array{created: int, updated: int, skipped: int, missing: int}
     *
     * @throws NagerDateUnavailable when a country or year couldn't be read (nothing is written)
     * @throws InvalidArgumentException for Nepal or a value that isn't a country code
     */
    public static function handle(Account $account, array $countries, array $years): array
    {
        $countries = collect($countries)->map(fn (string $code): string => strtoupper(trim($code)))->unique()->values()->all();
        $years = collect($years)->map(fn (int|string $year): int => (int) $year)->unique()->sort()->values()->all();

        foreach ($countries as $country) {
            if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
                throw new InvalidArgumentException("\"{$country}\" is not a country code.");
            }

            if ($country === CalendarEvent::NEPAL) {
                throw new InvalidArgumentException("Nepal's dates come from the official list, not Nager.Date.");
            }
        }

        if ($countries === [] || $years === []) {
            return ['created' => 0, 'updated' => 0, 'skipped' => 0, 'missing' => 0];
        }

        // Read everything before writing: a country that can't be read stops the import.
        $holidays = NagerDate::holidays($countries, $years);
        $rows = [];

        foreach ($holidays as $country => $byYear) {
            foreach ($byYear as $year => $items) {
                array_push($rows, ...self::rows($country, $year, $items));
            }
        }

        return Cache::lock("socialhq:events-import:{$account->id}", 120)->block(30, fn (): array => DB::transaction(function () use ($account, $rows, $countries, $years): array {
            $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'missing' => 0];

            foreach ($rows as $row) {
                $counts[WriteImportedEvent::handle($account, $row, fn ($query) => $query->where('country_code', data_get($row, 'country_code')))]++;
            }

            $counts['missing'] = self::missing($account, $rows, $countries, $years);

            return $counts;
        }));
    }

    /**
     * One row per holiday and day: Nager.Date lists a regional holiday once
     * per group of regions, so the same name on the same day is merged.
     *
     * @param  list<array{date: string, name: string, local_name: string, global: bool, counties: list<string>, types: list<string>}>  $items
     * @return list<array<string, mixed>>
     */
    private static function rows(string $country, int $year, array $items): array
    {
        return collect($items)
            ->map(fn (array $item): array => [...$item, 'name' => self::clean($item['name'], 160)])
            ->groupBy(fn (array $item): string => ImportOfficialEvents::keyFor($item['name']).'|'.$item['date'])
            ->map(function ($same) use ($country, $year): array {
                $first = $same->first();
                $types = $same->flatMap(fn (array $item): array => $item['types'])->unique()->values()->all();
                $global = $same->contains(fn (array $item): bool => $item['global']);
                $counties = $global ? [] : $same->flatMap(fn (array $item): array => $item['counties'])->unique()->sort()->values()->all();
                $public = in_array('Public', $types, true);
                $localName = self::clean($first['local_name'], 160);

                return [
                    'key' => ImportOfficialEvents::keyFor($first['name']),
                    'name' => $first['name'],
                    'name_ne' => $localName === null || $localName === $first['name'] ? null : $localName,
                    'category' => $public ? EventCategory::PublicHoliday : EventCategory::Observance,
                    'region' => EventRegion::Country,
                    'country_code' => $country,
                    'starts_on' => $first['date'],
                    'ends_on' => $first['date'],
                    'bs_date' => null,
                    'public_holiday' => $public,
                    'description' => self::description($public, $types, $counties),
                    'source_url' => NagerDate::sourceUrl($country, $year),
                    'source_name' => self::SOURCE_NAME,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $types
     * @param  list<string>  $counties
     */
    private static function description(bool $public, array $types, array $counties): ?string
    {
        $dayOffFor = collect(self::DAY_OFF_FOR)->only($types)->values();

        return self::clean(collect([
            $counties === [] ? null : 'Only in: '.implode(', ', $counties).'.',
            $public || $dayOffFor->isEmpty() ? null : 'A day off for '.$dayOffFor->implode(', ').' only.',
            ! $public && $dayOffFor->isEmpty() && in_array('Optional', $types, true) ? 'An optional day off.' : null,
        ])->filter()->implode(' '), 1000);
    }

    /**
     * How many imported holidays of these countries and years Nager.Date no longer lists.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $countries
     * @param  list<int>  $years
     */
    private static function missing(Account $account, array $rows, array $countries, array $years): int
    {
        $listed = collect($rows)->map(fn (array $row): string => data_get($row, 'country_code').'|'.data_get($row, 'key').'|'.data_get($row, 'starts_on'))->flip();

        return CalendarEvent::query()
            ->where('account_id', $account->id)
            ->whereIn('country_code', $countries)
            ->whereNotNull('import_key')
            ->where('import_date', '>=', min($years).'-01-01')
            ->where('import_date', '<=', max($years).'-12-31')
            ->get(['id', 'country_code', 'import_key', 'import_date'])
            ->filter(fn (CalendarEvent $event): bool => in_array($event->import_date?->year, $years, true))
            ->reject(fn (CalendarEvent $event): bool => $listed->has("{$event->country_code}|{$event->import_key}|{$event->import_date?->toDateString()}"))
            ->count();
    }

    private static function clean(?string $value, int $limit): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        return $value === '' ? null : Str::limit($value, $limit - 1, '…');
    }
}
