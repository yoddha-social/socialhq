<?php

declare(strict_types=1);

namespace App\SocialHq\Plan\Ai;

use App\Enums\PostPlatform\ContentType;
use App\Enums\User\WeekStart;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\SocialHq\Events\CalendarEvent;
use App\SocialHq\Knowledge\Audience\Audience;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\CreativeKind;
use App\SocialHq\Plan\ItemSource;
use App\SocialHq\Plan\ItemStatus;
use App\SocialHq\Plan\MustHave;
use App\SocialHq\Plan\PlanItem;
use App\SocialHq\Plan\PlanMonth;
use App\SocialHq\Plan\PlannerOptions;
use App\SocialHq\Plan\PlanOptions;
use App\SocialHq\Plan\SuggestedTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The planner's hard rules, kept in code instead of hoped for from the model,
 * for one week of a run. Where the fix is certain, an item is repaired rather
 * than thrown away: a channel key with a typo (or the channel's name, or the
 * only channel that takes the format), a format of another network (the same
 * kind on this one), what to make that the format can't carry, an event named
 * instead of keyed, a pillar in another case, and a day that is taken, one of
 * the run's days off, a day the channel doesn't post (switched off in its
 * posting times), or too far from its event (the nearest free day of the same
 * calendar week, so a week's posts per week never spill into the next). What
 * can't be repaired is refused with a reason. It also says how many posts
 * each channel still needs in the week, and on which days, for the prompt and
 * the retry.
 *
 * The team's must-haves are guaranteed here too. Each one of the week that no
 * kept item covers yet is counted first, on its channel (for "any", the
 * active channel with the most room that week that takes its format), so
 * what the AI is asked for leaves room for it. The AI's item that names it is
 * put on its day, channel and format where the team set them; one it missed
 * is added by the app itself. A must-have goes on its day even when that is a
 * day off.
 */
final class SuggestionChecks
{
    /** Posts a week asked of a channel without posts per week (the prompt used to say "2 or 3"). */
    public const DEFAULT_POSTS_PER_WEEK = 2;

    /** Weekday names as the prompt's "days" write them, from 0 (Sunday). */
    public const WEEKDAY_NAMES = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    /** What a format's value ends with, read as the kind of post it is (a reel stays a reel on another network). */
    private const KINDS = ['reel' => 'reel', 'short' => 'reel', 'video' => 'reel', 'story' => 'story', 'feed' => 'post', 'post' => 'post'];

    /** What to make instead, in order, when the format can't carry what the model asked for. */
    private const CREATIVE_FALLBACKS = [
        'image' => [CreativeKind::Image, CreativeKind::Video, CreativeKind::None],
        'carousel' => [CreativeKind::Carousel, CreativeKind::Image, CreativeKind::Video, CreativeKind::None],
        'video' => [CreativeKind::Video, CreativeKind::Image, CreativeKind::None],
        'document' => [CreativeKind::Document, CreativeKind::Image, CreativeKind::None],
        'none' => [CreativeKind::None, CreativeKind::Image, CreativeKind::Video],
        '' => [CreativeKind::Image, CreativeKind::Video, CreativeKind::None, CreativeKind::Document],
    ];

    private CarbonImmutable $month;

    private CarbonImmutable $monthEnd;

    /** @var Collection<string, SocialAccount> */
    private Collection $channels;

    /** @var array<string, int|null> */
    private array $cadence;

    /** @var Collection<int, CalendarEvent> */
    private Collection $events;

    /** @var list<string> */
    private array $pillars;

    private string $zone;

    /** @var array<string, true> channel id + day already holding an item */
    private array $taken;

    /** @var array<string, true> titles already in the month, compared without case */
    private array $titles;

    /** @var array<string, int> items per channel and calendar week (the requester's week start) */
    private array $weekly;

    /** @var array<string, list<int>> weekdays (0 Sunday to 6 Saturday) a channel's posting times switch off */
    private array $daysOff;

    /** @var list<MustHave> the week's must-haves no kept item covers yet, until check() places them */
    private array $openMustHaves;

    /** @var array<string, array{channel: SocialAccount, content_type: ContentType}> where each open must-have counts until it is placed, by its id */
    private array $reserved = [];

    /**
     * @param  PlannerOptions  $week  the days this call plans
     * @param  list<int>  $replaceable  suggestions the run will replace, which don't count as planned
     */
    public function __construct(
        private readonly ContentPlan $plan,
        public readonly PlannerOptions $week,
        private readonly CarbonImmutable $today,
        private readonly WeekStart $weekStart,
        ?string $userZone,
        public readonly array $replaceable,
    ) {
        $workspace = $plan->workspace;
        $profile = PlanOptions::profile($workspace);
        $this->month = CarbonImmutable::createFromFormat('!Y-m-d', $plan->month->toDateString(), 'UTC');
        $this->monthEnd = PlanMonth::end($this->month);
        $channels = PlanOptions::channels($workspace);
        $this->channels = $channels->keyBy('id');
        $this->cadence = PlanOptions::cadence($profile, $channels);
        // The events the planner is shown: the week's days and the lead days after them.
        $this->events = PlanOptions::events($workspace, $week->from, $week->to->addDays(GeneratePlanSuggestions::EVENT_LEAD_DAYS), $week->eventCountries($profile));
        $this->pillars = PlanOptions::pillars($profile);
        $this->zone = Audience::mainZone($profile, $userZone);
        $this->daysOff = $channels->mapWithKeys(fn (SocialAccount $channel): array => [$channel->id => self::daysOff($channel)])->all();

        // Everything that stays: planned items, suggestions someone started work on, and suggestions the run won't replace.
        $planned = $plan->items()
            ->where('status', '!=', ItemStatus::Dropped)
            ->whereKeyNot($replaceable)
            ->get(['id', 'social_account_id', 'scheduled_on', 'title', 'must_have']);

        $this->taken = $planned
            ->mapWithKeys(fn (PlanItem $item): array => [self::slot($item->social_account_id, $item->scheduled_on->toImmutable()) => true])
            ->all();
        $this->titles = $planned->mapWithKeys(fn (PlanItem $item): array => [self::titleKey($item->title) => true])->all();
        $this->weekly = $planned
            ->countBy(fn (PlanItem $item): string => $this->weekSlot($item->social_account_id, $item->scheduled_on->toImmutable()))
            ->all();

        // A must-have an item that stays already carries (someone accepted it) is covered.
        $covered = $planned->pluck('must_have')->filter()->flip()->all();
        $this->openMustHaves = collect($week->mustHavesInDays())
            ->reject(fn (MustHave $mustHave): bool => $mustHave->date->lessThan($today) || isset($covered[$mustHave->key()]))
            ->unique(fn (MustHave $mustHave): string => $mustHave->key())
            ->values()
            ->all();

        foreach ($this->openMustHaves as $mustHave) {
            $placement = $this->placementFor($mustHave);

            if ($placement !== null) {
                $this->reserved[$mustHave->id] = $placement;
            }
        }
    }

    /**
     * Places the week's must-haves first (the AI's item that names one, else
     * one the app makes), then checks the model's other items in order: each
     * one accepted counts against the ones after it (same day, posts per
     * week, titles).
     *
     * @param  list<mixed>  $items
     * @return array{
     *     accepted: list<array<string, mixed>>,
     *     added: list<array<string, mixed>>,
     *     rejections: list<array{title: string, date: string, channel: string, platform: string|null, reason: string}>,
     *     feedback: list<array{title: string, date: string, channel: string, reason: string}>,
     *     repaired: int,
     * }
     */
    public function check(array $items): array
    {
        $items = array_map(fn (mixed $item): array => is_array($item) ? $item : [], array_values($items));
        $accepted = [];
        $added = [];
        $rejections = [];
        $feedback = [];
        $repaired = 0;

        // The item that covers a must-have: the first one naming it. A claim on anything else is dropped (the item stays an ordinary one).
        $claims = [];

        foreach ($items as $index => $item) {
            $id = Str::upper(trim((string) data_get($item, 'must_have')));

            if (isset($this->reserved[$id]) && ! isset($claims[$id])) {
                $claims[$id] = $index;
            }
        }

        foreach ($this->openMustHaves as $mustHave) {
            $placement = $this->reserved[$mustHave->id] ?? null;

            if ($placement === null) {
                continue;
            }

            // Placed now: from here on it counts as an item, not a reservation.
            unset($this->reserved[$mustHave->id]);

            if (isset($claims[$mustHave->id])) {
                [$attributes, $fixes] = $this->placeClaim($mustHave, $items[$claims[$mustHave->id]], $placement);
                $accepted[] = $attributes;
                $repaired += $fixes;
            } else {
                $added[] = $this->placeMustHave($mustHave, $placement);
            }
        }

        $this->openMustHaves = [];
        $claimed = array_flip($claims);

        foreach ($items as $index => $item) {
            if (isset($claimed[$index])) {
                continue;
            }

            [$attributes, $reason, $fixes, $channel] = $this->checkOne($item);
            $repaired += $fixes;

            if ($attributes !== null) {
                $accepted[] = $attributes;

                continue;
            }

            $title = Str::limit(trim((string) data_get($item, 'title')), 160, '');
            $dateText = Str::limit(trim((string) data_get($item, 'date')), 20, '');
            $channelText = trim((string) data_get($item, 'channel'));

            $rejections[] = [
                'title' => $title,
                'date' => $dateText,
                'channel' => $channel?->display_label ?? Str::limit($channelText, 60, ''),
                // The page adds the network, so same-named channels stay apart.
                'platform' => $channel?->platform->value,
                'reason' => $reason,
            ];
            // What the model is told on its second try: its own words back, with the channel's key when known.
            $feedback[] = ['title' => $title, 'date' => $dateText, 'channel' => $channel?->id ?? Str::limit($channelText, 60, ''), 'reason' => $reason];
        }

        return ['accepted' => $accepted, 'added' => $added, 'rejections' => $rejections, 'feedback' => $feedback, 'repaired' => $repaired];
    }

    /**
     * How many posts each channel still needs in the week, and the days it
     * has free (not gone, not a day off, not switched off, not taken). A
     * calendar week cut by the month's edge asks for its share of the posts
     * per week only. The week's open must-haves count as posts already.
     *
     * @return array<string, array{posts: int, days: list<string>}>
     */
    public function openSlots(): array
    {
        $open = [];

        foreach ($this->channels as $channel) {
            $posts = 0;
            $free = [];

            if (data_get($this->cadence, $channel->id) !== 0) {
                $byWeek = collect($this->weekDays())
                    ->reject(fn (CarbonImmutable $day): bool => $this->isOff($channel, $day) || $this->week->isDayOff($day))
                    ->groupBy(fn (CarbonImmutable $day): string => $day->startOfWeek($this->weekStart->firstDay())->toDateString());

                foreach ($byWeek as $start => $days) {
                    $calendarWeek = CarbonImmutable::createFromFormat('!Y-m-d', $start, 'UTC');
                    $freeDays = $days->reject(fn (CarbonImmutable $day): bool => $this->isTaken($channel->id, $day))->values();

                    $posts += min($freeDays->count(), max(0, $this->target($channel, $calendarWeek) - $this->countInWeek($channel->id, $calendarWeek)));
                    $free = [...$free, ...$freeDays->map(fn (CarbonImmutable $day): string => $day->toDateString())->all()];
                }
            }

            $open[$channel->id] = ['posts' => $posts, 'days' => $posts > 0 ? $free : []];
        }

        return $open;
    }

    /**
     * The week's must-haves the AI is asked to cover, as the prompt lists them.
     *
     * @return list<array{id: string, date: string, channel: string, content_type: string, focus: string}>
     */
    public function mustHavesForPrompt(): array
    {
        return collect($this->openMustHaves)
            ->filter(fn (MustHave $mustHave): bool => isset($this->reserved[$mustHave->id]))
            ->map(fn (MustHave $mustHave): array => [
                'id' => $mustHave->id,
                'date' => $mustHave->date->toDateString(),
                'channel' => $this->fixedChannel($mustHave) ? $this->reserved[$mustHave->id]['channel']->id : 'any',
                'content_type' => $mustHave->contentType === null ? 'any' : $this->reserved[$mustHave->id]['content_type']->value,
                'focus' => $mustHave->focus,
            ])
            ->values()
            ->all();
    }

    /**
     * The run's days off as the prompt names them ("Sat").
     *
     * @return list<string>
     */
    public function daysOffForPrompt(): array
    {
        return array_map(fn (int $day): string => self::WEEKDAY_NAMES[$day], $this->week->daysOff);
    }

    public function plan(): ContentPlan
    {
        return $this->plan;
    }

    public function workspace(): Workspace
    {
        return $this->plan->workspace;
    }

    public function hasOpenSlots(): bool
    {
        return collect($this->openSlots())->sum('posts') > 0;
    }

    /**
     * Whether the week has must-haves still to place (check() places them all).
     */
    public function hasOpenMustHaves(): bool
    {
        return $this->reserved !== [];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{0: array<string, mixed>|null, 1: string|null, 2: int, 3: SocialAccount|null}
     */
    private function checkOne(array $item): array
    {
        $fixes = 0;
        $title = Str::limit(trim((string) data_get($item, 'title')), 160, '');
        $dateText = trim((string) data_get($item, 'date'));
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateText) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $dateText, 'UTC') : null;
        $channelText = trim((string) data_get($item, 'channel'));
        $typeText = Str::lower(trim((string) data_get($item, 'content_type')));
        $creativeText = Str::lower(trim((string) data_get($item, 'creative')));

        /** @var SocialAccount|null $channel */
        $channel = $this->channels->get($channelText);

        if ($channel === null && ($channel = $this->guessChannel($channelText, $typeText)) !== null) {
            $fixes++;
        }

        // Only kept items count as repaired.
        $reject = fn (string $reason): array => [null, $reason, 0, $channel];

        if ($title === '') {
            return $reject('missing_title');
        }

        if ($date === null || $date->toDateString() !== $dateText || ! $date->betweenIncluded($this->month, $this->monthEnd)) {
            return $reject('date_outside_month');
        }

        if ($date->lessThan($this->today)) {
            return $reject('date_in_past');
        }

        if (! $date->betweenIncluded($this->week->from, $this->week->to)) {
            return $reject('date_outside_range');
        }

        if ($channel === null) {
            return $reject('unknown_channel');
        }

        $perWeek = data_get($this->cadence, $channel->id);

        if ($perWeek === 0) {
            return $reject('channel_paused');
        }

        $creative = CreativeKind::tryFrom($creativeText);
        $contentType = ContentType::tryFrom($typeText);

        if ($contentType === null || ! PlanOptions::fitsChannel($contentType, $channel->platform)) {
            $contentType = self::contentTypeFor($typeText, $channel, $creative);
            $fixes++;
        }

        if ($creative === null || ! PlanOptions::creativeFits($creative, $contentType)) {
            $creative = collect(self::CREATIVE_FALLBACKS[$creative?->value ?? ''])
                ->first(fn (CreativeKind $kind): bool => PlanOptions::creativeFits($kind, $contentType));
            $fixes++;

            if ($creative === null) {
                return $reject('creative_mismatch');
            }
        }

        $eventText = trim((string) data_get($item, 'event_key'));
        /** @var Collection<int, CalendarEvent>|null $confirmed */
        $confirmed = null;

        if ($eventText !== '') {
            $matches = $this->events->where('key', $eventText)->values();

            if ($matches->isEmpty()) {
                // The event's name instead of its key, written once.
                $named = $this->events->filter(fn (CalendarEvent $event): bool => Str::lower($event->name) === Str::lower($eventText) || Str::lower($event->key) === Str::lower($eventText));
                $matches = $named->pluck('key')->unique()->count() === 1 ? $named->values() : $matches;
                $fixes += $matches->isEmpty() ? 0 : 1;
            }

            if ($matches->isEmpty()) {
                return $reject('unknown_event');
            }

            $confirmed = $matches->filter(fn (CalendarEvent $event): bool => $event->isVerified())->values();

            if ($confirmed->isEmpty()) {
                return $reject('unconfirmed_event');
            }
        }

        if (isset($this->titles[self::titleKey($title)])) {
            return $reject('duplicate_title');
        }

        $fits = fn (CarbonImmutable $day): ?string => $this->dayProblem($channel, $day, $perWeek, $confirmed);
        $problem = $fits($date);

        if ($problem !== null) {
            // The nearest day of the same calendar week that breaks no rule, the earlier one on a tie.
            $calendarWeek = $date->startOfWeek($this->weekStart->firstDay())->toDateString();
            $nearest = collect($this->weekDays())
                ->filter(fn (CarbonImmutable $day): bool => $day->startOfWeek($this->weekStart->firstDay())->toDateString() === $calendarWeek && $fits($day) === null)
                ->sortBy(fn (CarbonImmutable $day): array => [abs((int) $date->diffInDays($day)), $day->getTimestamp()])
                ->first();

            if ($nearest === null) {
                return $reject($problem);
            }

            $date = $nearest;
            $fixes++;
        }

        $event = $confirmed?->first(fn (CalendarEvent $candidate): bool => self::fitsEvent($date, $candidate));

        return [$this->record($channel, $date, $contentType, $creative, $title, $item, $event), null, $fixes, $channel];
    }

    /**
     * The AI's item for a must-have, on the must-have's day, and on its
     * channel and in its format where the team set them. For "any" the AI's
     * channel and format stand when they can carry it. What doesn't fit is
     * repaired: what to make follows the format, an event the day can't have
     * is left off, and a missing or repeated title becomes the focus.
     *
     * @param  array<string, mixed>  $item
     * @param  array{channel: SocialAccount, content_type: ContentType}  $placement
     * @return array{0: array<string, mixed>, 1: int}
     */
    private function placeClaim(MustHave $mustHave, array $item, array $placement): array
    {
        $fixes = trim((string) data_get($item, 'date')) === $mustHave->date->toDateString() ? 0 : 1;
        $channelText = trim((string) data_get($item, 'channel'));
        $typeText = Str::lower(trim((string) data_get($item, 'content_type')));
        $creative = CreativeKind::tryFrom(Str::lower(trim((string) data_get($item, 'creative'))));
        $channel = $placement['channel'];

        if (! $this->fixedChannel($mustHave)) {
            $named = $this->channels->get($channelText) ?? $this->guessChannel($channelText, $typeText);
            $usable = $named !== null
                && data_get($this->cadence, $named->id) !== 0
                && ($mustHave->contentType === null || PlanOptions::fitsChannel($mustHave->contentType, $named->platform));
            $channel = $usable ? $named : $channel;
        }

        $fixes += $channel->id === $channelText ? 0 : 1;
        $asked = ContentType::tryFrom($typeText);
        $contentType = match (true) {
            $mustHave->contentType !== null && PlanOptions::fitsChannel($mustHave->contentType, $channel->platform) => $mustHave->contentType,
            $asked !== null && PlanOptions::fitsChannel($asked, $channel->platform) => $asked,
            default => self::contentTypeFor($typeText, $channel, $creative),
        };
        $fixes += $contentType === $asked ? 0 : 1;

        if ($creative === null || ! PlanOptions::creativeFits($creative, $contentType)) {
            $creative = collect(self::CREATIVE_FALLBACKS[$creative?->value ?? ''])
                ->first(fn (CreativeKind $kind): bool => PlanOptions::creativeFits($kind, $contentType)) ?? self::creativeFor($contentType);
            $fixes++;
        }

        $title = Str::limit(trim((string) data_get($item, 'title')), 160, '');

        if ($title === '' || isset($this->titles[self::titleKey($title)])) {
            $title = Str::limit($mustHave->focus, 160, '');
            $fixes++;
        }

        $eventText = trim((string) data_get($item, 'event_key'));
        $event = $eventText === '' ? null : $this->eventsNamed($eventText)
            ->first(fn (CalendarEvent $candidate): bool => $candidate->isVerified() && self::fitsEvent($mustHave->date, $candidate));
        $fixes += $eventText !== '' && $event === null ? 1 : 0;

        return [$this->record($channel, $mustHave->date, $contentType, $creative, $title, $item, $event, $mustHave), $fixes];
    }

    /**
     * A must-have the AI missed, made by the app: on its day and its
     * channel, in its format (the channel's usual one for "any"), with its
     * focus as the title and the objective.
     *
     * @param  array{channel: SocialAccount, content_type: ContentType}  $placement
     * @return array<string, mixed>
     */
    private function placeMustHave(MustHave $mustHave, array $placement): array
    {
        $contentType = $placement['content_type'];

        return $this->record(
            $placement['channel'],
            $mustHave->date,
            $contentType,
            self::creativeFor($contentType),
            Str::limit($mustHave->focus, 160, ''),
            ['objective' => $mustHave->focus],
            null,
            $mustHave,
        );
    }

    /**
     * Saves an item's place (its day on the channel, its week's count, its
     * title) and returns what is stored for it.
     *
     * @param  array<string, mixed>  $item  the model's item, for its pillar, objective, angle and time
     * @return array<string, mixed>
     */
    private function record(SocialAccount $channel, CarbonImmutable $date, ContentType $contentType, CreativeKind $creative, string $title, array $item, ?CalendarEvent $event, ?MustHave $mustHave = null): array
    {
        $this->taken[self::slot($channel->id, $date)] = true;
        $this->weekly[$this->weekSlot($channel->id, $date)] = data_get($this->weekly, $this->weekSlot($channel->id, $date), 0) + 1;
        $this->titles[self::titleKey($title)] = true;
        $pillarText = Str::lower(trim((string) data_get($item, 'pillar')));
        $time = SuggestedTime::normalize(data_get($item, 'suggested_time'));

        // A time already gone today (in the audience's zone) is dropped, not the item: the team picks a new one.
        if ($time !== null && SuggestedTime::isZone($this->zone) && SuggestedTime::at($date->toDateString(), $time, $this->zone)->lessThanOrEqualTo(now())) {
            $time = null;
        }

        return [
            'workspace_id' => $this->plan->workspace_id,
            'scheduled_on' => $date->toDateString(),
            'social_account_id' => $channel->id,
            'platform' => $channel->platform->value,
            'content_type' => $contentType,
            'creative' => $creative,
            'pillar' => collect($this->pillars)->first(fn (string $pillar): bool => Str::lower($pillar) === $pillarText),
            'title' => $title,
            'objective' => Str::limit(trim((string) data_get($item, 'objective')), 300, '') ?: ($mustHave === null ? null : Str::limit($mustHave->focus, 300, '')),
            'angle' => Str::limit(trim((string) data_get($item, 'angle')), 600, '') ?: null,
            'event_id' => $event?->id,
            // A time the code can't read is dropped, not the item: the team sets one by hand.
            'suggested_time' => $time,
            'suggested_timezone' => $time === null ? null : $this->zone,
            'status' => ItemStatus::Suggested,
            'source' => ItemSource::Ai,
            'created_by' => $this->plan->generation_requested_by,
            'must_have' => $mustHave?->key(),
        ];
    }

    /**
     * Where a must-have counts until it is placed: its own channel, or for
     * "any" the active channel with the most room in the must-have's
     * calendar week that takes its format (a free day breaking a tie, then
     * the channels' order), else the first active channel; in its format,
     * else the channel's usual one. Null when the brand has no channel.
     *
     * @return array{channel: SocialAccount, content_type: ContentType}|null
     */
    private function placementFor(MustHave $mustHave): ?array
    {
        $channel = $this->fixedChannel($mustHave) ? $this->channels->get($mustHave->channelId) : null;

        if ($channel === null) {
            $active = $this->channels->filter(fn (SocialAccount $candidate): bool => data_get($this->cadence, $candidate->id) !== 0);
            $fitting = $mustHave->contentType === null ? $active : $active->filter(fn (SocialAccount $candidate): bool => PlanOptions::fitsChannel($mustHave->contentType, $candidate->platform));
            $pool = $fitting->isNotEmpty() ? $fitting : ($active->isNotEmpty() ? $active : $this->channels);
            $best = null;

            foreach ($pool as $candidate) {
                $score = [$this->room($candidate, $mustHave->date), $this->isTaken($candidate->id, $mustHave->date) ? 0 : 1];

                if ($best === null || $score > $best[1]) {
                    $best = [$candidate, $score];
                }
            }

            $channel = $best[0] ?? null;
        }

        if ($channel === null) {
            return null;
        }

        $contentType = $mustHave->contentType !== null && PlanOptions::fitsChannel($mustHave->contentType, $channel->platform)
            ? $mustHave->contentType
            : self::usualFormat($channel);

        return ['channel' => $channel, 'content_type' => $contentType];
    }

    /**
     * Whether the team picked the must-have's channel (and it is still one of the brand's).
     */
    private function fixedChannel(MustHave $mustHave): bool
    {
        return $mustHave->channelId !== null && $this->channels->has($mustHave->channelId);
    }

    /**
     * Why a channel can't post on a day, or null when it can: the event's
     * days, the run's days off, the channel's posting days, one post a day,
     * its posts per week.
     *
     * @param  Collection<int, CalendarEvent>|null  $confirmed
     */
    private function dayProblem(SocialAccount $channel, CarbonImmutable $day, ?int $perWeek, ?Collection $confirmed): ?string
    {
        return match (true) {
            $confirmed !== null && ! $confirmed->contains(fn (CalendarEvent $event): bool => self::fitsEvent($day, $event)) => 'event_date_mismatch',
            $this->week->isDayOff($day) => 'plan_day_off',
            $this->isOff($channel, $day) => 'day_off',
            $this->isTaken($channel->id, $day) => 'duplicate_day',
            is_int($perWeek) && $this->countInWeek($channel->id, $day) >= $perWeek => 'over_cadence',
            default => null,
        };
    }

    /**
     * The events a must-have item's event_key points at, as checkOne() reads
     * it: by key, else the event's name (or key in another case) when it
     * names one event.
     *
     * @return Collection<int, CalendarEvent>
     */
    private function eventsNamed(string $text): Collection
    {
        $matches = $this->events->where('key', $text)->values();
        $named = $this->events->filter(fn (CalendarEvent $event): bool => Str::lower($event->name) === Str::lower($text) || Str::lower($event->key) === Str::lower($text));

        return $matches->isNotEmpty() || $named->pluck('key')->unique()->count() !== 1 ? $matches : $named->values();
    }

    /**
     * The channel the model meant when its key isn't one of ours, only when
     * exactly one fits: the key in another case or with a character or a
     * part wrong (the last part of a channel's key is random, so it names one
     * channel), the channel's name or network, or the only channel that takes
     * the format.
     */
    private function guessChannel(string $text, string $typeText): ?SocialAccount
    {
        $only = fn (Collection $found): ?SocialAccount => $found->count() === 1 ? $found->first() : null;
        $key = Str::lower($text);
        $match = null;

        if ($key !== '') {
            $tail = Str::afterLast($key, '-');
            $match = $only($this->channels->filter(fn (SocialAccount $channel): bool => Str::lower($channel->id) === $key));

            if ($match === null && mb_strlen($tail) >= 8) {
                $match = $only($this->channels->filter(fn (SocialAccount $channel): bool => Str::endsWith(Str::lower($channel->id), "-{$tail}")));
            }

            if ($match === null && mb_strlen($key) >= 30 && mb_strlen($key) <= 40) {
                $match = $only($this->channels->filter(fn (SocialAccount $channel): bool => levenshtein(Str::lower($channel->id), $key) <= 3));
            }

            $match ??= $only($this->channels->filter(fn (SocialAccount $channel): bool => Str::lower((string) $channel->display_label) === $key));
            $match ??= $only($this->channels->filter(fn (SocialAccount $channel): bool => $channel->platform->value === $key || Str::lower($channel->platform->label()) === $key));
        }

        $type = ContentType::tryFrom($typeText);

        return $match ?? ($type === null ? null : $only($this->channels->filter(fn (SocialAccount $channel): bool => PlanOptions::fitsChannel($type, $channel->platform))));
    }

    /**
     * A format this channel offers for one the model wrote for another
     * network (or misspelt): the same kind of post here (a reel stays a
     * reel), else the network's main format when it can carry what is to be
     * made, else the first that can.
     */
    private static function contentTypeFor(string $text, SocialAccount $channel, ?CreativeKind $creative): ContentType
    {
        $types = PlanOptions::contentTypesFor($channel->platform);
        $kind = data_get(self::KINDS, Str::afterLast($text, '_'));
        $sameKind = $kind === null ? null : collect($types)->first(fn (ContentType $type): bool => data_get(self::KINDS, Str::afterLast($type->value, '_')) === $kind);

        if ($sameKind !== null) {
            return $sameKind;
        }

        $default = ContentType::defaultFor($channel->platform);
        $candidates = collect([$default, ...$types])->filter(fn (ContentType $type): bool => in_array($type, $types, true));

        return $candidates->first(fn (ContentType $type): bool => $creative === null || PlanOptions::creativeFits($creative, $type))
            ?? $candidates->first()
            ?? $default;
    }

    /**
     * The channel's usual format: its network's main one.
     */
    private static function usualFormat(SocialAccount $channel): ContentType
    {
        $types = PlanOptions::contentTypesFor($channel->platform);
        $default = ContentType::defaultFor($channel->platform);

        return in_array($default, $types, true) ? $default : ($types[0] ?? $default);
    }

    /**
     * What a must-have the app adds is made as: the first the format can carry.
     */
    private static function creativeFor(ContentType $contentType): CreativeKind
    {
        return collect([CreativeKind::Image, CreativeKind::Video, CreativeKind::Carousel, CreativeKind::Document, CreativeKind::None])
            ->first(fn (CreativeKind $kind): bool => PlanOptions::creativeFits($kind, $contentType)) ?? CreativeKind::None;
    }

    /**
     * Weekdays the channel's posting times switch off. A schedule with every
     * day off says nothing about planning (the queue is simply unused).
     *
     * @return list<int>
     */
    private static function daysOff(SocialAccount $channel): array
    {
        $off = collect($channel->posting_schedule?->days() ?? [])
            ->reject(fn (array $day): bool => (bool) data_get($day, 'enabled', true))
            ->map(fn (array $day): int => (int) data_get($day, 'day'))
            ->values()
            ->all();

        return count($off) >= 7 ? [] : $off;
    }

    private function isOff(SocialAccount $channel, CarbonImmutable $day): bool
    {
        return in_array($day->dayOfWeek, data_get($this->daysOff, $channel->id, []), true);
    }

    /**
     * Whether the channel already has an item that day, or a must-have still counted there.
     */
    private function isTaken(string $channelId, CarbonImmutable $day): bool
    {
        if (isset($this->taken[self::slot($channelId, $day)])) {
            return true;
        }

        foreach ($this->reserved as $mustHaveId => $placement) {
            if ($placement['channel']->id === $channelId && $this->mustHave($mustHaveId)?->date->toDateString() === $day->toDateString()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The channel's items in the calendar week of a day, with the must-haves still counted there.
     */
    private function countInWeek(string $channelId, CarbonImmutable $day): int
    {
        $slot = $this->weekSlot($channelId, $day);
        $count = (int) data_get($this->weekly, $slot, 0);

        foreach ($this->reserved as $mustHaveId => $placement) {
            $date = $this->mustHave($mustHaveId)?->date;

            if ($placement['channel']->id === $channelId && $date !== null && $this->weekSlot($channelId, $date) === $slot) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Posts a channel is asked for in a calendar week: its posts per week
     * (2 when not set, 0 when paused), its share of them in a week cut by
     * the month's edge.
     */
    private function target(SocialAccount $channel, CarbonImmutable $day): int
    {
        $perWeek = data_get($this->cadence, $channel->id);

        if ($perWeek === 0) {
            return 0;
        }

        $calendarWeek = $day->startOfWeek($this->weekStart->firstDay());
        $daysInMonth = collect(range(0, 6))
            ->filter(fn (int $offset): bool => $calendarWeek->addDays($offset)->betweenIncluded($this->month, $this->monthEnd))
            ->count();

        return $daysInMonth >= 7 ? ($perWeek ?? self::DEFAULT_POSTS_PER_WEEK) : (int) ceil(($perWeek ?? self::DEFAULT_POSTS_PER_WEEK) * $daysInMonth / 7);
    }

    /**
     * Posts a channel still has room for in the calendar week of a day.
     */
    private function room(SocialAccount $channel, CarbonImmutable $day): int
    {
        return $this->target($channel, $day) - $this->countInWeek($channel->id, $day);
    }

    private function mustHave(string $id): ?MustHave
    {
        return collect($this->openMustHaves)->first(fn (MustHave $mustHave): bool => $mustHave->id === $id);
    }

    /**
     * The week's days that can still get a post: from today on.
     *
     * @return list<CarbonImmutable>
     */
    private function weekDays(): array
    {
        return collect(CarbonPeriod::create($this->week->from, $this->week->to))
            ->map(fn ($day): CarbonImmutable => CarbonImmutable::createFromFormat('!Y-m-d', $day->toDateString(), 'UTC'))
            ->reject(fn (CarbonImmutable $day): bool => $day->lessThan($this->today))
            ->values()
            ->all();
    }

    /**
     * An event's post goes on one of its days or up to EVENT_LEAD_DAYS before it.
     */
    private static function fitsEvent(CarbonImmutable $date, CalendarEvent $event): bool
    {
        $from = $event->starts_on->subDays(GeneratePlanSuggestions::EVENT_LEAD_DAYS)->toDateString();

        return $date->toDateString() >= $from && $date->toDateString() <= $event->ends_on->toDateString();
    }

    private static function slot(?string $channelId, CarbonImmutable $day): string
    {
        return "{$channelId}|{$day->toDateString()}";
    }

    /**
     * One channel's calendar week, starting on the requester's first day of the week.
     */
    private function weekSlot(?string $channelId, CarbonImmutable $date): string
    {
        return "{$channelId}|{$date->startOfWeek($this->weekStart->firstDay())->toDateString()}";
    }

    private static function titleKey(string $title): string
    {
        return Str::lower(Str::squish($title));
    }
}
