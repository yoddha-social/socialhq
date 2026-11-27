<?php

declare(strict_types=1);

use App\SocialHq\Events\CalendarEvent;
use App\SocialHq\Events\EventGroup;
use App\SocialHq\Events\EventsForMonth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/*
 * Owner decision 2026-11-27: the same holiday on the same day in several
 * countries is one event, read from one row per country (EventsForMonth::group).
 */

/**
 * A row as the events query returns it, not saved.
 *
 * @param  array<string, mixed>  $attributes
 */
function eventGroupRow(int $id, string $name, ?string $country, CarbonImmutable $day, array $attributes = []): CalendarEvent
{
    return (new CalendarEvent)->forceFill([
        'id' => $id,
        'workspace_id' => null,
        'key' => str($name)->slug()->toString(),
        'name' => $name,
        'category' => 'public_holiday',
        'region' => $country === null ? 'international' : 'country',
        'country_code' => $country,
        'starts_on' => $day->toDateString(),
        'ends_on' => $day->toDateString(),
        'verified_at' => null,
        ...$attributes,
    ]);
}

/**
 * @param  Collection<int, EventGroup>  $events
 * @return list<list<int>>
 */
function eventGroupIds(Collection $events): array
{
    return $events->map(fn (EventGroup $event): array => $event->ids())->values()->all();
}

beforeEach(function () {
    $this->day = CarbonImmutable::now('UTC')->addMonthNoOverflow()->startOfMonth()->addDays(14);
});

test('the same holiday on the same day is one event across countries; another day or another name stays apart', function () {
    $events = EventsForMonth::group(collect([
        eventGroupRow(1, 'Christmas Day', 'NP', $this->day),
        eventGroupRow(2, 'Christmas Day', 'AU', $this->day),
        eventGroupRow(3, 'Christmas Day', null, $this->day),
        eventGroupRow(4, 'Christmas Day', null, $this->day, ['workspace_id' => 'brand', 'region' => 'brand']),
        eventGroupRow(5, 'Christmas Day', 'AU', $this->day->addDay()),
        eventGroupRow(6, 'Boxing Day', 'AU', $this->day),
    ]));

    // International first, then the countries by code, then the brand's own date.
    expect(eventGroupIds($events))->toBe([[3, 2, 1, 4], [5], [6]])
        ->and($events->first()->countries())->toBe(['AU', 'NP']);
});

test('names the list already treats as one join: the same key under a new name, or the same name under another key', function () {
    $events = EventsForMonth::group(collect([
        eventGroupRow(1, 'Christmas Day', 'NP', $this->day),
        // Renamed after it was created: the key is still the old name's.
        eventGroupRow(2, 'Xmas', 'AU', $this->day, ['key' => 'christmas-day']),
        // Added by hand under another key, with a name that normalises the same.
        eventGroupRow(3, 'Xmas!', 'DE', $this->day, ['key' => 'christmas-de']),
        eventGroupRow(4, 'Christmas  day', 'US', $this->day, ['key' => 'christmas-us']),
    ]));

    expect(eventGroupIds($events))->toBe([[2, 3, 1, 4]])
        ->and($events->sole()->hasKey('christmas-de'))->toBeTrue()
        ->and($events->sole()->hasKey('christmas'))->toBeFalse();
});

test('an event links to its oldest confirmed row, else its oldest, and is confirmed once one country is', function () {
    $unchecked = EventsForMonth::group(collect([
        eventGroupRow(7, 'Christmas Day', 'AU', $this->day),
        eventGroupRow(4, 'Christmas Day', 'NP', $this->day),
    ]))->sole();

    expect($unchecked->id)->toBe(4)
        ->and($unchecked->isConfirmed())->toBeFalse()
        ->and($unchecked->confirmedCountries())->toBe([])
        ->and($unchecked->asPlanned()->ids())->toBe([7, 4]);

    $checked = EventsForMonth::group(collect([
        eventGroupRow(4, 'Christmas Day', 'NP', $this->day),
        eventGroupRow(9, 'Christmas Day', 'DE', $this->day, ['verified_at' => now()->subDay()]),
        eventGroupRow(7, 'Christmas Day', 'AU', $this->day, ['verified_at' => now(), 'ends_on' => $this->day->addDay()->toDateString()]),
    ]))->sole();

    expect($checked->id)->toBe(7)
        ->and($checked->key())->toBe('christmas-day')
        ->and($checked->isConfirmed())->toBeTrue()
        ->and($checked->countries())->toBe(['AU', 'DE', 'NP'])
        ->and($checked->confirmedCountries())->toBe(['AU', 'DE'])
        ->and($checked->endsOn()->toDateString())->toBe($this->day->addDay()->toDateString())
        // The planner gets only the confirmed countries, linked to the same row.
        ->and($checked->asPlanned()->ids())->toBe([7, 9])
        ->and($checked->asPlanned()->id)->toBe(7);
});
