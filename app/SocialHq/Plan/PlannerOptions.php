<?php

declare(strict_types=1);

namespace App\SocialHq\Plan;

use App\Enums\User\WeekStart;
use App\SocialHq\Knowledge\Audience\Audience;
use App\SocialHq\Knowledge\BrandProfile;
use Carbon\CarbonImmutable;

/**
 * What one "Generate with AI" run is asked for: the days it plans (inside
 * the plan's month, so every item stays in its month's plan) and which
 * sources the planner reads. Saved on the plan when a run starts
 * (generation_options); the three switches are also remembered on the
 * brand profile (planner_options) as the next run's defaults.
 */
final readonly class PlannerOptions
{
    /** A week of the run shorter than this joins the week next to it. */
    public const MIN_WEEK_DAYS = 3;

    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public bool $oldCalendars,
        public bool $pastPosts,
        public bool $audienceEvents,
    ) {}

    /**
     * The whole month with the brand's remembered switches: both history
     * sources on, and events for the audience's countries only when the
     * brand has an audience.
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
        ]);
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
        );
    }

    /**
     * The same options for other days (a week of the run).
     */
    public function between(CarbonImmutable $from, CarbonImmutable $to): self
    {
        return new self($from, $to, $this->oldCalendars, $this->pastPosts, $this->audienceEvents);
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
     * @return array{from: string, to: string, old_calendars: bool, past_posts: bool, audience_events: bool}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            ...$this->switches(),
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
}
