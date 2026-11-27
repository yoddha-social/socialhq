<?php

declare(strict_types=1);

namespace App\SocialHq\Events;

use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The events a brand sees in a date range, for the events page, the publish
 * calendar, the plan, the planner and MCP. Confirmed and unconfirmed alike:
 * callers that must only use confirmed dates check isConfirmed().
 *
 * `$countries` (ISO 3166-1 alpha-2 codes) keeps the events of an audience in
 * those countries: theirs plus every event without a country (international
 * days, a brand's own dates). Null keeps every country.
 */
final class EventsForMonth
{
    /**
     * Events overlapping [$from, $to] (calendar days), ordered by start date,
     * one per holiday and day (group()).
     *
     * @param  list<string>|null  $countries
     * @return Collection<int, EventGroup>
     */
    public static function forWorkspace(Workspace $workspace, CarbonImmutable $from, CarbonImmutable $to, ?array $countries = null): Collection
    {
        return self::group(self::rows($workspace, $from, $to, $countries));
    }

    /**
     * The rows behind those events, one per country and date, in the same order.
     *
     * @param  list<string>|null  $countries
     * @return Collection<int, CalendarEvent>
     */
    public static function rows(Workspace $workspace, CarbonImmutable $from, CarbonImmutable $to, ?array $countries = null): Collection
    {
        return self::query($workspace, $from, $to, $countries)->with('verifier:id,name')->get();
    }

    /**
     * One day under one name is one event (QA 2026-11-18; owner decision
     * 2026-11-27): rows that start on the same day under the same key, or the
     * same name once normalised (ImportOfficialEvents::keyFor), are one event
     * whatever their countries: Christmas Day for Australia and for Nepal, or
     * a country's New Year that is also the international day. Each row keeps
     * its own confirmation, source, flags and hides. Events come in the order
     * of their first row.
     *
     * @param  Collection<int, CalendarEvent>  $rows
     * @return Collection<int, EventGroup>
     */
    public static function group(Collection $rows): Collection
    {
        $rows = $rows->values();
        /** @var array<int, int> $parent row index => the index of a row of the same event */
        $parent = array_keys($rows->all());
        /** @var array<string, int> $seen day and key or name => the first row under it */
        $seen = [];

        foreach ($rows as $index => $row) {
            $day = $row->starts_on->toDateString();

            foreach (["{$day}|{$row->key}", "{$day}|".ImportOfficialEvents::keyFor($row->name)] as $token) {
                if (! isset($seen[$token])) {
                    $seen[$token] = $index;

                    continue;
                }

                [$mine, $theirs] = [self::root($parent, $index), self::root($parent, $seen[$token])];
                $parent[max($mine, $theirs)] = min($mine, $theirs);
            }
        }

        $roots = [];

        foreach (array_keys($parent) as $index) {
            $roots[$index] = self::root($parent, $index);
        }

        return $rows
            ->groupBy(fn (CalendarEvent $row, int $index): int => $roots[$index])
            ->map(fn (Collection $same): EventGroup => EventGroup::of($same))
            ->values();
    }

    /**
     * The calendar markers: for each day of [$from, $to] that has events, one
     * per event with its name, whether it is confirmed for planning, its
     * category and its countries.
     *
     * @param  list<string>|null  $countries
     * @return array<string, list<array{name: string, verified: bool, category: string, countries: list<string>}>>
     */
    public static function markersByDay(Workspace $workspace, CarbonImmutable $from, CarbonImmutable $to, ?array $countries = null): array
    {
        // Event dates are calendar days in the app's zone: compare days, not instants of the viewer's zone.
        $first = CarbonImmutable::parse($from->toDateString());
        $last = CarbonImmutable::parse($to->toDateString());
        $days = [];
        $rows = self::query($workspace, $first, $last, $countries)
            ->get(['id', 'workspace_id', 'key', 'name', 'category', 'country_code', 'starts_on', 'ends_on', 'verified_at']);

        foreach (self::group($rows) as $event) {
            $day = $event->startsOn()->max($first);
            $end = $event->endsOn()->min($last);

            while ($day->lessThanOrEqualTo($end)) {
                $days[$day->toDateString()][] = [
                    'name' => $event->name(),
                    'verified' => $event->isConfirmed(),
                    'category' => $event->category()->value,
                    'countries' => $event->countries(),
                ];
                $day = $day->addDay();
            }
        }

        return $days;
    }

    /**
     * @param  list<string>|null  $countries
     * @return Builder<CalendarEvent>
     */
    private static function query(Workspace $workspace, CarbonImmutable $from, CarbonImmutable $to, ?array $countries): Builder
    {
        return CalendarEvent::query()
            ->forBrand($workspace)
            ->forCountries($countries)
            ->overlapping($from->toDateString(), $to->toDateString())
            ->orderBy('starts_on')
            ->orderBy('name')
            ->orderBy('id');
    }

    /**
     * The first row of a row's event (a union-find over the rows).
     *
     * @param  array<int, int>  $parent
     */
    private static function root(array &$parent, int $index): int
    {
        while ($parent[$index] !== $index) {
            $parent[$index] = $parent[$parent[$index]];
            $index = $parent[$index];
        }

        return $index;
    }
}
