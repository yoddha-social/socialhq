<?php

declare(strict_types=1);

namespace App\SocialHq\Plan\Ai;

use App\Actions\Analytics\QueryLatestPublicationSnapshots;
use App\Enums\PostPlatform\ContentType;
use App\Models\SocialAccount;
use App\SocialHq\Events\CalendarEvent;
use App\SocialHq\Knowledge\BrandKnowledge;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\ItemStatus;
use App\SocialHq\Plan\PlanItem;
use App\SocialHq\Plan\PlanMonth;
use App\SocialHq\Plan\PlanOptions;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Everything the AI planner is told about a month, as one JSON-ready array:
 * the days, the team's goals, the brand, its pillars, its channels with their
 * cadence and formats, the month's events (confirmed or not, flagged), what is
 * already planned, and how recent posts did.
 */
final class PlannerContext
{
    /** How far back the past posts go. */
    public const PAST_DAYS = 90;

    /** At most this many past posts: the best and the worst first. */
    public const PAST_POSTS = 60;

    public const EXCERPT_LENGTH = 140;

    /**
     * @return array{
     *     month: string,
     *     days: list<string>,
     *     goals: string|null,
     *     brand: string,
     *     pillars: list<string>,
     *     channels: list<array{key: string, platform: string, name: string, posts_per_week: int|null, content_types: list<string>}>,
     *     events: list<array{key: string, name: string, category: string, starts_on: string, ends_on: string, confirmed: bool}>,
     *     already_planned: list<array{title: string, date: string, channel: string|null}>,
     *     past_posts: list<array<string, mixed>>,
     * }
     */
    public static function build(ContentPlan $plan): array
    {
        $workspace = $plan->workspace;
        $month = CarbonImmutable::createFromFormat('!Y-m-d', $plan->month->toDateString(), 'UTC');
        $end = PlanMonth::end($month);
        $profile = PlanOptions::profile($workspace);
        $channels = PlanOptions::channels($workspace);
        $cadence = PlanOptions::cadence($profile, $channels);

        return [
            'month' => PlanMonth::key($month),
            'days' => collect(CarbonPeriod::create($month, $end))
                ->map(fn ($day): string => $day->format('Y-m-d D'))
                ->values()
                ->all(),
            'goals' => filled($plan->goals) ? trim((string) $plan->goals) : null,
            'brand' => BrandKnowledge::forPrompt($workspace),
            'pillars' => PlanOptions::pillars($profile),
            'channels' => $channels->map(fn (SocialAccount $channel): array => [
                'key' => $channel->id,
                'platform' => $channel->platform->value,
                'name' => $channel->display_label,
                'posts_per_week' => data_get($cadence, $channel->id),
                'content_types' => array_map(fn (ContentType $type): string => $type->value, PlanOptions::contentTypesFor($channel->platform)),
            ])->values()->all(),
            'events' => PlanOptions::events($workspace, $month, $end)->map(fn (CalendarEvent $event): array => [
                'key' => $event->key,
                'name' => $event->name,
                'category' => $event->category->value,
                'starts_on' => $event->starts_on->toDateString(),
                'ends_on' => $event->ends_on->toDateString(),
                'confirmed' => $event->isVerified(),
            ])->values()->all(),
            'already_planned' => $plan->items()
                ->where('status', ItemStatus::Planned)
                ->orderBy('scheduled_on')
                ->orderBy('position')
                ->get()
                ->map(fn (PlanItem $item): array => [
                    'title' => $item->title,
                    'date' => $item->scheduled_on->toDateString(),
                    'channel' => $item->social_account_id,
                ])
                ->values()
                ->all(),
            'past_posts' => self::pastPosts($workspace->id),
        ];
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
        $rows = app(QueryLatestPublicationSnapshots::class)
            ->execute($workspaceId, null, CarbonImmutable::now()->subDays(self::PAST_DAYS), CarbonImmutable::now())
            ->leftJoin('posts as post', 'post.id', '=', 'publication.post_id')
            ->select([
                'publication.provider_published_at', 'publication.platform', 'publication.content_type', 'publication.excerpt',
                'post.content as post_content', 'metric.engagement_count', 'metric.reach_count', 'metric.views_count',
            ])
            ->orderByDesc('publication.provider_published_at')
            ->limit(500)
            ->get();

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
                'excerpt' => self::excerpt($row->excerpt ?? $row->post_content),
                'engagement' => self::number($row->engagement_count),
                'reach' => self::number($row->reach_count),
                'views' => self::number($row->views_count),
            ], fn (mixed $value): bool => $value !== null && $value !== ''))
            ->values()
            ->all();
    }

    private static function excerpt(?string $text): ?string
    {
        if (blank($text)) {
            return null;
        }

        return Str::of($text)->stripTags()->squish()->limit(self::EXCERPT_LENGTH - 1, '…')->toString();
    }

    private static function number(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
