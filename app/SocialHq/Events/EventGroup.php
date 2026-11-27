<?php

declare(strict_types=1);

namespace App\SocialHq\Events;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * One event as a brand sees it: the same holiday on the same day in several
 * countries (Christmas Day for Australia and for Nepal) is one event with a
 * row per country (owner decision 2026-11-27). The data stays one row per
 * country, each with its own confirmation, source, flags and hides, because a
 * date confirmed for Australia is no proof for Nepal: this only reads them
 * together. EventsForMonth::group() is the one place rows become events.
 *
 * The event counts as confirmed for planning when any of its rows is: the
 * brand sees only the rows of the countries it follows (and international
 * days and its own dates), so that is a country it follows. `id` is the row a
 * plan item links to: the oldest confirmed row, else the oldest row, so the
 * link doesn't move when another country is confirmed.
 */
final readonly class EventGroup
{
    /** The row items link to (primary()). */
    public int $id;

    /**
     * @param  Collection<int, CalendarEvent>  $rows  international first, then countries by code, then the brand's own
     */
    private function __construct(public Collection $rows)
    {
        $this->id = $this->primary()->id;
    }

    /**
     * @param  Collection<int, CalendarEvent>  $rows  the same holiday on the same day
     */
    public static function of(Collection $rows): self
    {
        return new self($rows->sortBy(fn (CalendarEvent $row): array => [
            match (true) {
                $row->workspace_id !== null => 2,
                $row->country_code === null => 0,
                default => 1,
            },
            (string) $row->country_code,
            $row->id,
        ])->values());
    }

    /**
     * The row that stands for the event: the oldest confirmed one, else the oldest.
     */
    public function primary(): CalendarEvent
    {
        $byAge = $this->rows->sortBy('id');

        return $byAge->first(fn (CalendarEvent $row): bool => $row->isVerified()) ?? $byAge->first();
    }

    public function key(): string
    {
        return $this->primary()->key;
    }

    /**
     * Whether the event is known by this key: any of its rows' (a renamed
     * date keeps the key it was created with).
     */
    public function hasKey(string $key): bool
    {
        return $this->rows->contains(fn (CalendarEvent $row): bool => $row->key === $key);
    }

    public function name(): string
    {
        return $this->primary()->name;
    }

    public function category(): EventCategory
    {
        return $this->primary()->category;
    }

    public function startsOn(): CarbonImmutable
    {
        return $this->primary()->starts_on;
    }

    /**
     * The last day of any of its rows.
     */
    public function endsOn(): CarbonImmutable
    {
        return $this->rows->map(fn (CalendarEvent $row): CarbonImmutable => $row->ends_on)->max();
    }

    /**
     * Confirmed for planning: a person confirmed at least one country's row.
     */
    public function isConfirmed(): bool
    {
        return $this->rows->contains(fn (CalendarEvent $row): bool => $row->isVerified());
    }

    /**
     * @return list<int>
     */
    public function ids(): array
    {
        return $this->rows->map(fn (CalendarEvent $row): int => $row->id)->values()->all();
    }

    /**
     * The countries whose holiday it is, by code (an international day or a
     * brand's own date has none).
     *
     * @return list<string>
     */
    public function countries(): array
    {
        return self::codes($this->rows);
    }

    /**
     * @return list<string>
     */
    public function confirmedCountries(): array
    {
        return self::codes($this->rows->filter(fn (CalendarEvent $row): bool => $row->isVerified()));
    }

    /**
     * The event as the planner gets it: only its confirmed rows once one is
     * confirmed, so the prompt names only the countries a person checked;
     * else every row, as an event still to check.
     */
    public function asPlanned(): self
    {
        $confirmed = $this->rows->filter(fn (CalendarEvent $row): bool => $row->isVerified());

        return $confirmed->isEmpty() || $confirmed->count() === $this->rows->count() ? $this : new self($confirmed->values());
    }

    /**
     * @param  Collection<int, CalendarEvent>  $rows
     * @return list<string>
     */
    private static function codes(Collection $rows): array
    {
        return $rows->pluck('country_code')->filter()->unique()->sort()->values()->all();
    }
}
