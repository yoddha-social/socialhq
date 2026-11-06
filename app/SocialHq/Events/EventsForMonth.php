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
 */
final class EventsForMonth
{
    /**
     * Events overlapping [$from, $to] (calendar days), ordered by start date.
     *
     * @return Collection<int, CalendarEvent>
     */
    public static function forWorkspace(Workspace $workspace, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return self::query($workspace, $from, $to)->with('verifier:id,name')->get();
    }

    /**
     * The calendar markers: for each day of [$from, $to] that has events, their
     * names, whether a person confirmed them and their category.
     *
     * @return array<string, list<array{name: string, verified: bool, category: string}>>
     */
    public static function markersByDay(Workspace $workspace, CarbonImmutable $from, CarbonImmutable $to): array
    {
        // Event dates are calendar days in the app's zone: compare days, not instants of the viewer's zone.
        $first = CarbonImmutable::parse($from->toDateString());
        $last = CarbonImmutable::parse($to->toDateString());
        $days = [];

        foreach (self::query($workspace, $first, $last)->get(['id', 'name', 'category', 'starts_on', 'ends_on', 'verified_at']) as $event) {
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
     * @return Builder<CalendarEvent>
     */
    private static function query(Workspace $workspace, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return CalendarEvent::query()
            ->visibleTo($workspace)
            ->overlapping($from->toDateString(), $to->toDateString())
            ->orderBy('starts_on')
            ->orderBy('name')
            ->orderBy('id');
    }
}
