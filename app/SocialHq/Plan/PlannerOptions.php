<?php

declare(strict_types=1);

namespace App\SocialHq\Plan;

use App\Enums\User\WeekStart;
use App\SocialHq\Knowledge\Audience\Audience;
use App\SocialHq\Knowledge\BrandProfile;
use Carbon\CarbonImmutable;

/**
 * What one "Generate with AI" run is asked for: the days it plans (inside
 * the plan's month, so every item stays in its month's plan), which sources
 * the planner reads, and the team's instructions: days off (weekdays that get
 * no suggestion) and must-have posts. Saved on the plan when a run starts
 * (generation_options); the three switches are also remembered on the brand
 * profile (planner_options) as the next run's defaults, with the strategy
 * and the days off while "Keep these instructions for next month" is on.
 */
final readonly class PlannerOptions
{
    /** A week of the run shorter than this joins the week next to it. */
    public const MIN_WEEK_DAYS = 3;

    /**
     * @param  list<int>  $daysOff  weekdays (0 Sunday to 6 Saturday) that get no suggestion, on any channel
     * @param  list<MustHave>  $mustHaves
     */
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public bool $oldCalendars,
        public bool $pastPosts,
        public bool $audienceEvents,
        public array $daysOff = [],
        public array $mustHaves = [],
    ) {}

    /**
     * The whole month with the brand's remembered switches (both history
     * sources on, and events for the audience's countries only when the brand
     * has an audience) and its remembered days off.
     */
    public static function defaults(CarbonImmutable $month, ?BrandProfile $profile): self
    {
        $saved = (array) ($profile?->planner_options ?? []);

        return new self(
            $month,
            PlanMonth::end($month),
            (bool) data_get($saved, 'old_calendars', true),
            (bool) data_get($saved, 'past_posts', true),
            (bool) data_get($saved, 'audience_events', Audience::locations($profile) !== []),
            self::weekdays(data_get($saved, 'days_off')),
        );
    }

    /**
     * The options the plan's latest run started with, kept inside the month
     * (a missing or broken value falls back to the defaults).
     */
    public static function forPlan(ContentPlan $plan, ?BrandProfile $profile): self
    {
        $month = CarbonImmutable::createFromFormat('!Y-m-d', $plan->month->toDateString(), 'UTC');
        $defaults = self::defaults($month, $profile);
        $saved = (array) ($plan->generation_options ?? []);

        return self::within($month, [
            'from' => data_get($saved, 'from'),
            'to' => data_get($saved, 'to'),
            'old_calendars' => data_get($saved, 'old_calendars', $defaults->oldCalendars),
            'past_posts' => data_get($saved, 'past_posts', $defaults->pastPosts),
            'audience_events' => data_get($saved, 'audience_events', $defaults->audienceEvents),
            'days_off' => data_get($saved, 'days_off', $defaults->daysOff),
            'must_haves' => data_get($saved, 'must_haves', []),
        ]);
    }

    /**
     * What "Generate with AI" opens with: the brand's switches; this plan's
     * days off once a run of it set them, else the brand's remembered ones;
     * the must-haves of the plan's last run that aren't past; and the plan's
     * strategy, else the one the brand remembers.
     *
     * @return array{from: string, to: string, old_calendars: bool, past_posts: bool, audience_events: bool, days_off: list<int>, must_haves: list<array<string, string|null>>, strategy: string}
     */
    public static function forDialog(?ContentPlan $plan, CarbonImmutable $month, ?BrandProfile $profile, string $today): array
    {
        $defaults = self::defaults($month, $profile);
        $saved = (array) ($plan?->generation_options ?? []);
        $mustHaves = array_filter(
            MustHave::listFrom(data_get($saved, 'must_haves'), $month),
            fn (MustHave $mustHave): bool => $mustHave->date->toDateString() >= $today,
        );

        return [
            ...$defaults->toArray(),
            'days_off' => array_key_exists('days_off', $saved) ? self::weekdays(data_get($saved, 'days_off')) : $defaults->daysOff,
            'must_haves' => array_values(array_map(fn (MustHave $mustHave): array => $mustHave->toArray(), $mustHaves)),
            'strategy' => filled($plan?->goals) ? (string) $plan->goals : (string) data_get((array) ($profile?->planner_options ?? []), 'strategy', ''),
        ];
    }

    /**
     * Options from submitted values, with the days clamped to the month and in order.
     *
     * @param  array<string, mixed>  $values
     */
    public static function within(CarbonImmutable $month, array $values): self
    {
        $end = PlanMonth::end($month);
        $day = fn (mixed $value, CarbonImmutable $fallback): CarbonImmutable => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            ? CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC')->max($month)->min($end)
            : $fallback;
        $from = $day(data_get($values, 'from'), $month);
        $to = $day(data_get($values, 'to'), $end);

        return new self(
            $from->min($to),
            $to->max($from),
            (bool) data_get($values, 'old_calendars', true),
            (bool) data_get($values, 'past_posts', true),
            (bool) data_get($values, 'audience_events', false),
            self::weekdays(data_get($values, 'days_off')),
            MustHave::listFrom(data_get($values, 'must_haves'), $month),
        );
    }

    /**
     * The same options for other days (a week of the run).
     */
    public function between(CarbonImmutable $from, CarbonImmutable $to): self
    {
        return new self($from, $to, $this->oldCalendars, $this->pastPosts, $this->audienceEvents, $this->daysOff, $this->mustHaves);
    }

    /**
     * The run's days as the weeks the AI is asked for one at a time: calendar
     * weeks from the requester's first day of the week, where a piece shorter
     * than MIN_WEEK_DAYS at either end joins the week next to it (so 1 to 3
     * January makes no call of its own).
     *
     * @return list<self>
     */
    public function weeks(WeekStart $weekStart): array
    {
        $pieces = [];

        for ($start = $this->from; $start->lessThanOrEqualTo($this->to); $start = $end->addDay()) {
            $end = $start->startOfWeek($weekStart->firstDay())->addDays(6)->min($this->to);
            $pieces[] = [$start, $end];
        }

        $days = fn (array $piece): int => (int) $piece[0]->diffInDays($piece[1]) + 1;

        if (count($pieces) > 1 && $days($pieces[0]) < self::MIN_WEEK_DAYS) {
            $first = array_shift($pieces);
            $pieces[0][0] = $first[0];
        }

        if (count($pieces) > 1 && $days($pieces[count($pieces) - 1]) < self::MIN_WEEK_DAYS) {
            $last = array_pop($pieces);
            $pieces[count($pieces) - 1][1] = $last[1];
        }

        return array_map(fn (array $piece): self => $this->between($piece[0], $piece[1]), $pieces);
    }

    /**
     * The must-haves dated in these days.
     *
     * @return list<MustHave>
     */
    public function mustHavesInDays(): array
    {
        return array_values(array_filter($this->mustHaves, fn (MustHave $mustHave): bool => $mustHave->date->betweenIncluded($this->from, $this->to)));
    }

    public function isDayOff(CarbonImmutable $day): bool
    {
        return in_array($day->dayOfWeek, $this->daysOff, true);
    }

    /**
     * The audience's countries when events are limited to them, else null
     * (every event). A brand without an audience gets every event.
     *
     * @return list<string>|null
     */
    public function eventCountries(?BrandProfile $profile): ?array
    {
        $countries = $this->audienceEvents ? Audience::countries($profile) : [];

        return $countries === [] ? null : $countries;
    }

    /**
     * @return array{from: string, to: string, old_calendars: bool, past_posts: bool, audience_events: bool, days_off: list<int>, must_haves: list<array<string, string|null>>}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            ...$this->switches(),
            'days_off' => $this->daysOff,
            'must_haves' => array_map(fn (MustHave $mustHave): array => $mustHave->toArray(), $this->mustHaves),
        ];
    }

    /**
     * @return array{old_calendars: bool, past_posts: bool, audience_events: bool}
     */
    public function switches(): array
    {
        return [
            'old_calendars' => $this->oldCalendars,
            'past_posts' => $this->pastPosts,
            'audience_events' => $this->audienceEvents,
        ];
    }

    /**
     * What the brand remembers for its next run (planner_options): the
     * switches always; the strategy and the days off only while the team
     * keeps its instructions, so turning that off clears them.
     *
     * @return array<string, mixed>
     */
    public function remembered(?string $strategy, bool $keepInstructions): array
    {
        return [
            ...$this->switches(),
            ...($keepInstructions ? ['strategy' => trim((string) $strategy), 'days_off' => $this->daysOff] : []),
        ];
    }

    /**
     * Weekdays from submitted or saved values: 0 (Sunday) to 6, each once, in order.
     *
     * @return list<int>
     */
    public static function weekdays(mixed $values): array
    {
        $days = array_filter(
            is_array($values) ? $values : [],
            fn (mixed $day): bool => is_int($day) || (is_string($day) && preg_match('/^[0-6]$/', $day) === 1),
        );
        $days = array_values(array_unique(array_filter(array_map(intval(...), $days), fn (int $day): bool => $day >= 0 && $day <= 6)));
        sort($days);

        return $days;
    }
}
