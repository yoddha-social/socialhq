<?php

declare(strict_types=1);

namespace App\SocialHq\Events;

use App\Models\Account;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Uri;
use InvalidArgumentException;
use Throwable;

/**
 * Loads the official dates (database/data/socialhq/events/*.csv) into an
 * account, as account-wide events that a person still has to confirm.
 *
 * Each imported event remembers its row of the list (import_key +
 * import_date), and a re-import matches on that, wherever the team has
 * since moved the event. A new row is created unconfirmed; a row that
 * changed in the file is updated without touching its timestamps, so a later
 * import can still tell it apart from a row a person edited. Rows a person
 * added, edited, narrowed to one brand or deleted are never overwritten or
 * brought back, and an unchanged row keeps its confirmation. Rows that left
 * the list are counted, not deleted. Only the list's own rows (Nepal's and
 * the international ones) are matched and counted: other countries'
 * holidays belong to ImportPublicHolidays.
 */
final class ImportOfficialEvents
{
    public const DIRECTORY = 'data/socialhq/events';

    public const FILES = ['nepal-2083.csv', 'international.csv'];

    private const NEPAL_CATEGORIES = [
        'festival' => EventCategory::Festival,
        'public_holiday' => EventCategory::PublicHoliday,
        'national_day' => EventCategory::NationalDay,
        'observance' => EventCategory::Observance,
    ];

    private const INTERNATIONAL_CATEGORIES = [
        'international_day' => EventCategory::InternationalDay,
        'commercial' => EventCategory::Commercial,
        'global_holiday' => EventCategory::Observance,
    ];

    /**
     * `missing` counts the account's imported dates that are no longer in the
     * official list (only when the whole shipped dataset is read).
     *
     * @param  list<string>|null  $paths  CSV files to read; the shipped dataset when null.
     * @return array{created: int, updated: int, skipped: int, missing: int}
     */
    public static function handle(Account $account, ?array $paths = null): array
    {
        $wholeDataset = $paths === null;
        $paths ??= array_map(fn (string $file): string => database_path(self::DIRECTORY.'/'.$file), self::FILES);

        // Read and check every file first: a bad row stops the import before anything is written.
        // A day listed in both files (Christmas, Women's Day) is one event: the first file wins,
        // so the Nepal list's public holiday information is kept.
        $rows = collect($paths)
            ->flatMap(fn (string $path): array => self::rows($path))
            ->unique(fn (array $row): string => data_get($row, 'key').'|'.data_get($row, 'starts_on'))
            ->values()
            ->all();

        return Cache::lock("socialhq:events-import:{$account->id}", 120)->block(30, fn (): array => DB::transaction(function () use ($account, $rows, $wholeDataset): array {
            $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'missing' => 0];

            foreach ($rows as $row) {
                $counts[WriteImportedEvent::handle($account, $row, fn ($query) => $query->fromOfficialList())]++;
            }

            if ($wholeDataset) {
                $counts['missing'] = self::missing($account, $rows);
            }

            return $counts;
        }));
    }

    /**
     * The event key for a name: a slug that stays the same across years.
     */
    public static function keyFor(string $name): string
    {
        // A name with no Latin letters slugs to nothing: key it by a hash, so two such names don't clash.
        return Str::limit(Str::slug($name), 80, '') ?: 'event-'.substr(hash('sha256', $name), 0, 10);
    }

    /**
     * How many of the account's imported dates (not deleted) are no longer in the list.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private static function missing(Account $account, array $rows): int
    {
        $listed = collect($rows)->map(fn (array $row): string => data_get($row, 'key').'|'.data_get($row, 'starts_on'))->flip();

        return CalendarEvent::query()
            ->where('account_id', $account->id)
            ->fromOfficialList()
            ->whereNotNull('import_key')
            ->get(['id', 'import_key', 'import_date'])
            ->reject(fn (CalendarEvent $event): bool => $listed->has("{$event->import_key}|{$event->import_date?->toDateString()}"))
            ->count();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("Can't read the events file {$path}.");
        }

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, escape: '');

        if (! is_array($header)) {
            fclose($handle);

            throw new InvalidArgumentException("The events file {$path} is empty.");
        }

        $header = array_map(fn (?string $column): string => trim((string) $column, "\u{FEFF} \t"), $header);
        $nepal = in_array('date_bs', $header, true);
        $required = $nepal
            ? ['date_ad', 'date_bs', 'name_en', 'name_ne', 'category', 'public_holiday', 'region_or_community', 'source_url', 'source_name', 'notes']
            : ['date_ad', 'name_en', 'category', 'rule', 'source_url', 'notes'];

        if ($missing = array_diff($required, $header)) {
            fclose($handle);

            throw new InvalidArgumentException("The events file {$path} lacks the columns: ".implode(', ', $missing).'.');
        }

        $rows = [];
        $line = 1;

        while (($values = fgetcsv($handle, escape: '')) !== false) {
            $line++;

            if ($values === [null] || $values === []) {
                continue;
            }

            $record = array_combine($header, array_pad(array_slice($values, 0, count($header)), count($header), ''));

            try {
                $rows[] = $nepal ? self::nepalRow($record) : self::internationalRow($record);
            } catch (Throwable $exception) {
                fclose($handle);

                throw new InvalidArgumentException("{$path} line {$line}: {$exception->getMessage()}", previous: $exception);
            }
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param  array<string, string|null>  $record
     * @return array<string, mixed>
     */
    private static function nepalRow(array $record): array
    {
        $category = self::NEPAL_CATEGORIES[trim((string) data_get($record, 'category'))]
            ?? throw new InvalidArgumentException('unknown category "'.data_get($record, 'category').'".');
        $holiday = strtolower(trim((string) data_get($record, 'public_holiday')));

        if (! in_array($holiday, ['yes', 'no', 'partial'], true)) {
            throw new InvalidArgumentException('public_holiday must be yes, no or partial, not "'.data_get($record, 'public_holiday').'".');
        }

        $where = trim((string) data_get($record, 'region_or_community'));
        $description = collect([
            $where === '' ? null : ($holiday === 'partial' ? "Holiday only for: {$where}." : "Observed in or by: {$where}."),
            self::readableNotes(data_get($record, 'notes')),
        ])->filter()->implode(' ');

        return [
            ...self::common($record, $category),
            'region' => EventRegion::Nepal,
            'country_code' => CalendarEvent::NEPAL,
            'bs_date' => self::clean(data_get($record, 'date_bs'), 60),
            'name_ne' => self::clean(data_get($record, 'name_ne'), 160),
            'public_holiday' => $holiday !== 'no',
            'description' => self::clean($description, 1000),
            'source_name' => self::clean(self::sourceLabel((string) data_get($record, 'source_name')), 160),
        ];
    }

    /**
     * @param  array<string, string|null>  $record
     * @return array<string, mixed>
     */
    private static function internationalRow(array $record): array
    {
        $category = self::INTERNATIONAL_CATEGORIES[trim((string) data_get($record, 'category'))]
            ?? throw new InvalidArgumentException('unknown category "'.data_get($record, 'category').'".');
        $rule = self::clean(data_get($record, 'rule'));
        $notes = self::clean(data_get($record, 'notes'));
        $host = self::host(data_get($record, 'source_url'));

        return [
            ...self::common($record, $category),
            'region' => EventRegion::International,
            'country_code' => null,
            'bs_date' => null,
            'name_ne' => null,
            'public_holiday' => false,
            'description' => self::clean(collect([
                $rule === null || Str::startsWith($rule, 'fixed') ? null : "Every year: {$rule}.",
                self::readableNotes($notes),
            ])->filter()->implode(' '), 400),
            'source_name' => $host === null ? null : Str::limit(Str::chopStart($host, 'www.'), 160, ''),
        ];
    }

    /**
     * @param  array<string, string|null>  $record
     * @return array<string, mixed>
     */
    private static function common(array $record, EventCategory $category): array
    {
        $name = self::clean(data_get($record, 'name_en'), 160) ?? throw new InvalidArgumentException('name_en is empty.');
        $date = trim((string) data_get($record, 'date_ad'));

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || CarbonImmutable::createFromFormat('!Y-m-d', $date)?->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException("date_ad \"{$date}\" is not a YYYY-MM-DD date.");
        }

        $url = self::clean(data_get($record, 'source_url'), 500);

        return [
            'key' => self::keyFor($name),
            'name' => $name,
            'category' => $category,
            'starts_on' => $date,
            'ends_on' => $date,
            'source_url' => self::host($url) === null ? null : $url,
        ];
    }

    /**
     * The dataset's notes are research notes ("[official] Notice §2.1(क). AD
     * date per ..."). People see only what tells them about the day, plus a
     * warning when one calendar alone gives the date or it is tentative.
     */
    private static function readableNotes(?string $notes): ?string
    {
        $notes = trim((string) $notes);

        if ($notes === '') {
            return null;
        }

        $singleSource = Str::contains($notes, '(single source)');
        $tentative = Str::contains(Str::lower($notes), ['tentative', 'probably']);
        $text = trim((string) preg_replace('/^\[[^\]]*\]\s*/', '', $notes));

        $kept = collect(preg_split('/(?<=[.;])\s+/', $text) ?: [])
            ->map(fn (string $part): string => trim(rtrim(trim($part), ';.')))
            ->reject(fn (string $part): bool => $part === '' || preg_match('/agree|pradhan|weekday|notice|§|rat32|hamro patro|cross-?check|date from|listed on|https?:\/\/|source/i', $part) === 1)
            ->map(fn (string $part): string => Str::ucfirst($part).'.');

        return collect([
            $kept->implode(' '),
            $tentative ? 'The date is tentative: check it before planning around it.' : null,
            $singleSource && ! $tentative ? 'Only one calendar gives this date: check it before planning around it.' : null,
        ])->filter()->implode(' ') ?: null;
    }

    /**
     * A short name for where the date comes from, without the dataset's
     * research detail (gazette numbers, cross-check links).
     */
    private static function sourceLabel(string $source): string
    {
        $source = trim($source);

        return match (true) {
            Str::startsWith($source, 'MOHA') => 'Ministry of Home Affairs holiday notice',
            Str::contains($source, 'myRepublica') => 'myRepublica, citing the Ministry of Home Affairs',
            Str::startsWith($source, 'Hamro Patro') && Str::contains($source, 'cross-checked') => 'Hamro Patro, checked against nepalicalendar.rat32.com',
            default => trim((string) preg_replace('/\s*\([^)]*\)\s*$/', '', $source)),
        };
    }

    /**
     * The host of an http(s) link, or null when the value is not one.
     */
    private static function host(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }

        $uri = Uri::of($url);

        return in_array($uri->scheme(), ['http', 'https'], true) && filled($uri->host()) ? $uri->host() : null;
    }

    private static function clean(?string $value, ?int $limit = null): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        if ($value === '') {
            return null;
        }

        return $limit === null ? $value : Str::limit($value, $limit - 1, '…');
    }
}
