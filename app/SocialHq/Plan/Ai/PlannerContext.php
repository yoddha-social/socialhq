<?php

declare(strict_types=1);

namespace App\SocialHq\Plan\Ai;

use App\Enums\PostPlatform\ContentType;
use App\Enums\User\WeekStart;
use App\Models\SocialAccount;
use App\SocialHq\Events\CalendarEvent;
use App\SocialHq\Knowledge\Audience\Audience;
use App\SocialHq\Knowledge\BrandKnowledge;
use App\SocialHq\Knowledge\Calendars\OldCalendars;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\ItemStatus;
use App\SocialHq\Plan\PastPerformance;
use App\SocialHq\Plan\PlanItem;
use App\SocialHq\Plan\PlanMonth;
use App\SocialHq\Plan\PlannerOptions;
use App\SocialHq\Plan\PlanOptions;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * Everything the AI planner is told for one call, as one JSON-ready array:
 * the days to plan (a week of the run), the team's goals, the brand, where
 * its audience is, its pillars, its channels with their cadence, formats,
 * the posts each still needs in these days and the days it has free, the
 * days' events (confirmed or not, flagged; only the audience's countries'
 * and international ones when asked), what is already planned, and, when
 * the run asks for them, how recent posts did and the team's old calendars.
 * What a run leaves out is not sent at all, to keep the prompt small.
 */
final class PlannerContext
{
    /** How far back the past posts go. */
    public const PAST_DAYS = PastPerformance::DAYS;

    /** At most this many past posts: the best and the worst first. */
    public const PAST_POSTS = 60;

    public const EXCERPT_LENGTH = PastPerformance::EXCERPT_LENGTH;

    /**
     * @return array{
     *     month: string,
     *     today: string,
     *     now: string,
     *     range: array{from: string, to: string},
     *     days: list<string>,
     *     goals: string|null,
     *     brand: string,
     *     audience: array{main_timezone: string, locations: list<array<string, mixed>>},
     *     pillars: list<string>,
     *     channels: list<array{key: string, platform: string, name: string, posts_per_week: int|null, content_types: list<string>, posts_to_plan: int, free_days: list<string>}>,
     *     events: list<array{key: string, name: string, category: string, starts_on: string, ends_on: string, confirmed: bool}>,
     *     already_planned: list<array{title: string, date: string, channel: string|null, pillar: string}>,
     *     past_posts?: list<array<string, mixed>>,
     *     old_calendar?: list<array<string, mixed>>,
     * }
     */
    public static function build(ContentPlan $plan, CarbonImmutable $today, ?string $fallbackZone = null, ?SuggestionChecks $checks = null): array
    {
        $workspace = $plan->workspace;
        $month = CarbonImmutable::createFromFormat('!Y-m-d', $plan->month->toDateString(), 'UTC');
        $profile = PlanOptions::profile($workspace);
        if ($checks === null) {
            // No week given: the run's days as one call.
            $run = PlannerOptions::forPlan($plan, $profile);
            $checks = new SuggestionChecks($plan, $run, $today, WeekStart::DEFAULT, $fallbackZone, GeneratePlanSuggestions::replaceableSuggestions($plan, $run)->pluck('id')->all());
        }

        $options = $checks->week;
        $open = $checks->openSlots();
        $channels = PlanOptions::channels($workspace);
        $cadence = PlanOptions::cadence($profile, $channels);
        // Events up to EVENT_LEAD_DAYS after the range too: their posts may go out in it.
        $events = PlanOptions::events($workspace, $options->from, $options->to->addDays(GeneratePlanSuggestions::EVENT_LEAD_DAYS), $options->eventCountries($profile));
        $pillars = PlanOptions::pillars($profile);
        $mainZone = Audience::mainZone($profile, $fallbackZone);

        return [
            'month' => PlanMonth::key($month),
            'today' => $today->toDateString(),
            'now' => now($mainZone)->format('Y-m-d H:i'),
            'range' => ['from' => $options->from->toDateString(), 'to' => $options->to->toDateString()],
            'days' => collect(CarbonPeriod::create($options->from, $options->to))
                ->map(fn ($day): string => $day->format('Y-m-d D'))
                ->values()
                ->all(),
            'goals' => filled($plan->goals) ? trim((string) $plan->goals) : null,
            'brand' => BrandKnowledge::forPrompt($workspace, self::focus($plan, $pillars, $events, $channels)),
            'audience' => Audience::forPlanner($profile, $mainZone),
            'pillars' => $pillars,
            'channels' => $channels->map(fn (SocialAccount $channel): array => [
                'key' => $channel->id,
                'platform' => $channel->platform->value,
                'name' => $channel->display_label,
                'posts_per_week' => data_get($cadence, $channel->id),
                'content_types' => array_map(fn (ContentType $type): string => $type->value, PlanOptions::contentTypesFor($channel->platform)),
                // Worked out in code: what the channel still needs in these days, and where it fits.
                'posts_to_plan' => (int) data_get($open, "{$channel->id}.posts", 0),
                'free_days' => data_get($open, "{$channel->id}.days", []),
            ])->values()->all(),
            'events' => $events->map(fn (CalendarEvent $event): array => [
                'key' => $event->key,
                'name' => $event->name,
                'category' => $event->category->value,
                'starts_on' => $event->starts_on->toDateString(),
                'ends_on' => $event->ends_on->toDateString(),
                'confirmed' => $event->isVerified(),
            ])->values()->all(),
            // Everything this draft keeps: planned items and the suggestions it won't replace.
            'already_planned' => $plan->items()
                ->where('status', '!=', ItemStatus::Dropped)
                ->whereKeyNot($checks->replaceable)
                ->orderBy('scheduled_on')
                ->orderBy('position')
                ->get()
                ->map(fn (PlanItem $item): array => [
                    'title' => $item->title,
                    'date' => $item->scheduled_on->toDateString(),
                    'channel' => $item->social_account_id,
                    // So a week's call can keep the month's pillars balanced.
                    'pillar' => $item->pillar ?? '',
                ])
                ->values()
                ->all(),
            ...($options->pastPosts ? ['past_posts' => self::pastPosts($workspace->id)] : []),
            ...($options->oldCalendars ? ['old_calendar' => OldCalendars::forPlanner($workspace->id, $month)] : []),
        ];
    }

    /**
     * What the month is about, for picking brand knowledge: the team's goals,
     * the pillars, the confirmed events and the channels' networks.
     *
     * @param  list<string>  $pillars
     * @param  Collection<int, CalendarEvent>  $events
     * @param  Collection<int, SocialAccount>  $channels
     */
    public static function focus(ContentPlan $plan, array $pillars, Collection $events, Collection $channels): string
    {
        return collect([
            trim((string) $plan->goals),
            implode(', ', $pillars),
            $events->filter(fn (CalendarEvent $event): bool => $event->isVerified())->pluck('name')->implode(', '),
            $channels->map(fn (SocialAccount $channel): string => $channel->platform->label())->unique()->implode(', '),
        ])->filter()->implode("\n");
    }

    /**
     * The workspace's published posts of the last 90 days with their latest
     * metrics, best and worst by engagement first, then the newest without
     * metrics, up to 60.
     *
     * @return list<array<string, mixed>>
     */
    public static function pastPosts(string $workspaceId): array
    {
        $rows = PastPerformance::rows($workspaceId);

        /** @var Collection<int, object> $measured */
        $measured = $rows->filter(fn (object $row): bool => $row->engagement_count !== null)
            ->sortByDesc(fn (object $row): int => (int) $row->engagement_count)
            ->values();
        $unmeasured = $rows->filter(fn (object $row): bool => $row->engagement_count === null)->values();

        $half = intdiv(self::PAST_POSTS, 2);
        $picked = $measured->count() > self::PAST_POSTS
            ? $measured->take($half)->concat($measured->slice(-$half)->reverse())
            : $measured;

        return $picked
            ->concat($unmeasured)
            ->take(self::PAST_POSTS)
            ->map(fn (object $row): array => array_filter([
                'date' => CarbonImmutable::parse($row->provider_published_at, 'UTC')->toDateString(),
                'platform' => $row->platform,
                'content_type' => $row->content_type,
                'excerpt' => PastPerformance::excerpt($row->excerpt ?? $row->post_content),
                'engagement' => PastPerformance::number($row->engagement_count),
                'reach' => PastPerformance::number($row->reach_count),
                'views' => PastPerformance::number($row->views_count),
            ], fn (mixed $value): bool => $value !== null && $value !== ''))
            ->values()
            ->all();
    }
}
