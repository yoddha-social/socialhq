<?php

declare(strict_types=1);

use App\Enums\User\WeekStart;
use App\SocialHq\Plan\PlannerOptions;
use Carbon\CarbonImmutable;

/**
 * The days of each week a run is split into, as "from..to" offsets from $start.
 *
 * @return list<string>
 */
function plannerWeeks(CarbonImmutable $start, int $days, WeekStart $weekStart): array
{
    $options = new PlannerOptions($start, $start->addDays($days - 1), true, true, false);

    return array_map(
        fn (PlannerOptions $week): string => (int) $start->diffInDays($week->from).'..'.(int) $start->diffInDays($week->to),
        $options->weeks($weekStart),
    );
}

test('a run is asked for one calendar week at a time, a short piece at either end joining its neighbour', function (int $weekday, int $days, WeekStart $weekStart, array $expected) {
    // A day on the wanted weekday, never a literal date.
    $start = CarbonImmutable::now('UTC')->startOfDay()->next($weekday);

    expect(plannerWeeks($start, $days, $weekStart))->toBe($expected);
})->with([
    'four whole weeks from a Monday' => [CarbonImmutable::MONDAY, 28, WeekStart::Monday, ['0..6', '7..13', '14..20', '21..27']],
    'a month from a Friday: the first three days are a week of their own' => [CarbonImmutable::FRIDAY, 31, WeekStart::Monday, ['0..2', '3..9', '10..16', '17..23', '24..30']],
    'a Sunday alone joins the next week, and a Monday alone the one before' => [CarbonImmutable::SUNDAY, 30, WeekStart::Monday, ['0..7', '8..14', '15..21', '22..29']],
    'weeks starting on Sunday' => [CarbonImmutable::SUNDAY, 14, WeekStart::Sunday, ['0..6', '7..13']],
    'a week from a Wednesday spans two calendar weeks' => [CarbonImmutable::WEDNESDAY, 7, WeekStart::Monday, ['0..6']],
    'two days alone stay one call' => [CarbonImmutable::SATURDAY, 2, WeekStart::Monday, ['0..1']],
]);
