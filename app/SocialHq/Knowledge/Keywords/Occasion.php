<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Keywords;

use App\Models\Workspace;
use App\SocialHq\Events\CalendarEvent;
use App\SocialHq\Events\EventsForMonth;
use Carbon\CarbonImmutable;

/**
 * When an occasion keyword is in season. A keyword tagged with an event
 * keeps the event's key without its year ("nepali-new-year-2083" is
 * "nepali-new-year"), so the tag still holds when next year's dates are
 * imported and the festival moves: any listed date of that occasion the
 * brand sees opens a window from `days_before` the first day to
 * `days_after` the last (socialhq.knowledge.keywords.occasion). A range of
 * months holds every day of those months, every year, and may run over the
 * new year (November to February).
 *
 * The key never names a country, so one tag covers every country's date of
 * the holiday (Christmas Day for Australia and for Nepal share
 * "christmas-day"). A holiday the events list reads as one event
 * (EventsForMonth::group: the same day under the same name) is offered once,
 * and puts every key its countries' dates carry in season, so a tag stored
 * from any of them still holds.
 */
final class Occasion
{
    /** How far ahead the tag picker lists the brand's dates. */
    private const int CHOICE_DAYS_AHEAD = 400;

    /** How far back it still lists one that just passed. */
    private const int CHOICE_DAYS_BACK = 30;

    /** What reading the brand's dates as events (EventsForMonth::group) needs. */
    private const array COLUMNS = ['id', 'workspace_id', 'country_code', 'key', 'name', 'starts_on', 'ends_on', 'verified_at'];

    /**
     * An event's key without a year: the same occasion every year.
     */
    public static function key(string $eventKey): string
    {
        $key = (string) preg_replace('/(^|-)(19|20|21)\d{2}(?=-|$)/', '', $eventKey);

        return trim($key, '-') ?: $eventKey;
    }

    public static function daysBefore(): int
    {
        return max(0, (int) config('socialhq.knowledge.keywords.occasion.days_before', 14));
    }

    public static function daysAfter(): int
    {
        return max(0, (int) config('socialhq.knowledge.keywords.occasion.days_after', 2));
    }

    /**
     * The occasions in season on a day for this brand: each listed date it
     * sees whose window holds the day, by occasion key, with its name. An
     * event of several countries brings every key its rows carry.
     *
     * @return array<string, string>
     */
    public static function activeOn(Workspace $workspace, CarbonImmutable $day): array
    {
        $active = [];

        $rows = CalendarEvent::query()
            ->forBrand($workspace)
            ->listed()
            ->overlapping($day->subDays(self::daysAfter())->toDateString(), $day->addDays(self::daysBefore())->toDateString())
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get(self::COLUMNS);

        foreach (EventsForMonth::group($rows) as $event) {
            foreach ($event->rows as $row) {
                $active[self::key($row->key)] ??= $event->name();
            }
        }

        return $active;
    }

    /**
     * Whether a month (1 to 12) is in a range; from after to runs over the new year.
     */
    public static function monthsHold(int $from, int $to, int $month): bool
    {
        return $from <= $to ? $month >= $from && $month <= $to : $month >= $from || $month <= $to;
    }

    /**
     * The brand's occasions for the tag picker: one per occasion, named and
     * dated by its next date (else the one that just passed), soonest first.
     *
     * @return list<array{key: string, name: string, starts_on: string}>
     */
    public static function choices(Workspace $workspace): array
    {
        $today = CarbonImmutable::today();
        $choices = [];

        $rows = CalendarEvent::query()
            ->forBrand($workspace)
            ->listed()
            ->overlapping($today->subDays(self::CHOICE_DAYS_BACK)->toDateString(), $today->addDays(self::CHOICE_DAYS_AHEAD)->toDateString())
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get(self::COLUMNS);

        // One choice per event, however many countries' holiday it is.
        foreach (EventsForMonth::group($rows) as $event) {
            $key = self::key($event->key());
            $known = $choices[$key] ?? null;

            // The next date wins over one that has passed.
            if ($known === null || ($known['starts_on'] < $today->toDateString() && $event->startsOn()->greaterThanOrEqualTo($today))) {
                $choices[$key] = ['key' => $key, 'name' => $event->name(), 'starts_on' => $event->startsOn()->toDateString()];
            }
        }

        $choices = array_values($choices);
        usort($choices, fn (array $left, array $right): int => [$left['starts_on'], $left['name']] <=> [$right['starts_on'], $right['name']]);

        return $choices;
    }

    /**
     * A range of months as words ("March to April", "December"), in the app's language.
     */
    public static function monthsLabel(int $from, int $to): string
    {
        $name = fn (int $month): string => CarbonImmutable::create(2000, $month, 1)->translatedFormat('F');

        return $from === $to
            ? $name($from)
            : __('socialhq.keywords.tags.months_range', ['from' => $name($from), 'to' => $name($to)]);
    }
}
