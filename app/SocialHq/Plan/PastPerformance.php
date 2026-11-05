<?php

declare(strict_types=1);

namespace App\SocialHq\Plan;

use App\Actions\Analytics\QueryLatestPublicationSnapshots;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * How the brand's recent posts did: the workspace's published posts of the
 * last 90 days with their latest metrics. The AI planner reads them
 * (PlannerContext) and the month page shows the team what worked lately.
 */
final class PastPerformance
{
    /** How far back the posts go. */
    public const DAYS = 90;

    /** At most this many posts are read, newest first. */
    public const READ_LIMIT = 500;

    public const EXCERPT_LENGTH = 140;

    /** The metrics a post is ranked by, in order of preference. */
    public const METRICS = ['engagement', 'reach', 'views'];

    public const BEST = 3;

    public const WEAKEST = 2;

    /** A format needs this many measured posts before it can be the best one. */
    public const FORMAT_MIN_POSTS = 2;

    /**
     * The published posts of the window with their latest metrics, newest
     * first, as raw rows (publication, post and latest snapshot columns).
     *
     * @return Collection<int, object>
     */
    public static function rows(string $workspaceId): Collection
    {
        $now = CarbonImmutable::now();

        return app(QueryLatestPublicationSnapshots::class)
            ->execute($workspaceId, null, $now->subDays(self::DAYS), $now)
            ->leftJoin('posts as post', 'post.id', '=', 'publication.post_id')
            ->select([
                'publication.id', 'publication.provider_published_at', 'publication.platform', 'publication.content_type',
                'publication.excerpt', 'publication.permalink', 'publication.account_display_name', 'publication.account_username',
                'publication.account_avatar_url', 'post.content as post_content', 'post.platform_url as post_platform_url',
                'metric.engagement_count', 'metric.reach_count', 'metric.views_count',
            ])
            ->orderByDesc('publication.provider_published_at')
            ->orderByDesc('publication.id')
            ->limit(self::READ_LIMIT)
            ->get();
    }

    /**
     * What worked lately, for people: the best and weakest posts by one
     * metric (engagement, else reach, else views: the first any post has), and
     * the format with the best average among those with enough posts.
     *
     * @return array{
     *     metric: string|null,
     *     best: list<array<string, mixed>>,
     *     weakest: list<array<string, mixed>>,
     *     best_format: array{content_type: string, average: int, posts: int}|null,
     * }
     */
    public static function lately(string $workspaceId): array
    {
        $rows = self::rows($workspaceId);
        $metric = collect(self::METRICS)
            ->first(fn (string $name): bool => $rows->contains(fn (object $row): bool => self::number($row->{"{$name}_count"}) !== null));

        if ($metric === null) {
            return ['metric' => null, 'best' => [], 'weakest' => [], 'best_format' => null];
        }

        $measured = $rows
            ->filter(fn (object $row): bool => self::number($row->{"{$metric}_count"}) !== null)
            ->sortByDesc(fn (object $row): int => (int) self::number($row->{"{$metric}_count"}))
            ->values();

        $best = $measured->take(self::BEST);
        $weakest = $measured->slice(self::BEST)->reverse()->take(self::WEAKEST);

        return [
            'metric' => $metric,
            'best' => $best->map(fn (object $row): array => self::post($row, $metric))->values()->all(),
            'weakest' => $weakest->map(fn (object $row): array => self::post($row, $metric))->values()->all(),
            'best_format' => self::bestFormat($measured, $metric),
        ];
    }

    public static function excerpt(?string $text): ?string
    {
        if (blank($text)) {
            return null;
        }

        return Str::of($text)->stripTags()->squish()->limit(self::EXCERPT_LENGTH - 1, '…')->toString();
    }

    public static function number(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function post(object $row, string $metric): array
    {
        return [
            'id' => $row->id,
            'date' => CarbonImmutable::parse($row->provider_published_at, 'UTC')->toDateString(),
            'platform' => $row->platform,
            'content_type' => $row->content_type,
            'channel' => $row->account_display_name ?? $row->account_username,
            'avatar_url' => $row->account_avatar_url,
            'excerpt' => self::excerpt($row->excerpt ?? $row->post_content),
            'value' => self::number($row->{"{$metric}_count"}),
            'url' => $row->post_platform_url ?? $row->permalink,
        ];
    }

    /**
     * @param  Collection<int, object>  $measured
     * @return array{content_type: string, average: int, posts: int}|null
     */
    private static function bestFormat(Collection $measured, string $metric): ?array
    {
        return $measured
            ->filter(fn (object $row): bool => filled($row->content_type) && $row->content_type !== 'unknown')
            ->groupBy('content_type')
            ->filter(fn (Collection $posts): bool => $posts->count() >= self::FORMAT_MIN_POSTS)
            ->map(fn (Collection $posts, string $type): array => [
                'content_type' => $type,
                'average' => (int) round($posts->avg(fn (object $row): int => (int) self::number($row->{"{$metric}_count"}))),
                'posts' => $posts->count(),
            ])
            ->sortByDesc('average')
            ->first();
    }
}
