<?php

declare(strict_types=1);

namespace App\SocialHq\Events;

use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The events a brand sees in a date range, for the events page, the publish
 * calendar and the planner. Confirmed and unconfirmed alike: callers that
 * must only use confirmed dates check isVerified().
 *
 * `$countries` (ISO 3166-1 alpha-2 codes) keeps the events of an audience in
 * those countries: theirs plus every event without a country (international
 * days, a brand's own dates). Null keeps every country.
 */
final class EventsForMonth
{
    /**
     * Events overlapping [$from, $to] (calendar days), ordered by start date.
     *
     * @param  list<string>|null  $countries
     * @return Collection<int, CalendarEvent>
     */
    public static function forWorkspace(Workspace $workspace, CarbonImmutable $from, CarbonImmutable $to, ?array $countries = null): Collection
    {
        return self::withoutRepeats(self::query($workspace, $from, $to, $countries)->with('verifier:id,name')->get());
    }

    /**
     * One day under one name is one event (QA 2026-11-18): a country's
     * holiday that is also an international day ("New Year's Day", key and
     * start date the same) shows once, as the international day, and names
     * the countries in `also_countries`.
     *
     * @param  Collection<int, CalendarEvent>  $events
     * @return Collection<int, CalendarEvent>
     */
    public static function withoutRepeats(Collection $events): Collection
    {
        $repeated = [];

        foreach ($events->groupBy(fn (CalendarEvent $event): string => "{$event->key}|{$event->starts_on->toDateString()}") as $same) {
            $kept = $same->first(fn (CalendarEvent $event): bool => $event->country_code === null);

            if ($same->count() < 2 || $kept === null) {
                continue;
            }

            $others = $same->reject(fn (CalendarEvent $event): bool => $event->is($kept));
            $kept->setAttribute('also_countries', $others->pluck('country_code')->filter()->unique()->sort()->values()->all());
            array_push($repeated, ...$others->modelKeys());
        }

        return $events->reject(fn (CalendarEvent $event): bool => in_array($event->getKey(), $repeated, true))->values();
    }

    /**
     * The calendar markers: for each day of [$from, $to] that has events, their
     * names, whether a person confirmed them and their category.
     *
     * @param  list<string>|null  $countries
     * @return array<string, list<array{name: string, verified: bool, category: string}>>
     */
    public static function markersByDay(Workspace $workspace, CarbonImmutable $from, CarbonImmutable $to, ?array $countries = null): array
    {
        // Event dates are calendar days in the app's zone: compare days, not instants of the viewer's zone.
        $first = CarbonImmutable::parse($from->toDateString());
        $last = CarbonImmutable::parse($to->toDateString());
        $days = [];

        foreach (self::withoutRepeats(self::query($workspace, $first, $last, $countries)->get(['id', 'key', 'name', 'category', 'country_code', 'starts_on', 'ends_on', 'verified_at'])) as $event) {
            $day = $event->starts_on->max($first);
            $end = $event->ends_on->min($last);

            while ($day->lessThanOrEqualTo($end)) {
                $days[$day->toDateString()][] = [
                    'name' => $event->name,
                    'verified' => $event->isVerified(),
                    'category' => $event->category->value,
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
}
