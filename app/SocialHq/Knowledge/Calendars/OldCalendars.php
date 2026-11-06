<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Calendars;

use App\Models\Workspace;
use App\SocialHq\Plan\PastPerformance;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A brand's imported old calendars: the list on the Brand knowledge page,
 * an import's rows, and what the AI planner reads from them.
 */
final class OldCalendars
{
    /** At most this many old rows reach the planner. */
    public const int PLANNER_ROWS = 60;

    /** An import's rows shown on the page, newest first. */
    public const int ROWS_SHOWN = 200;

    private const int TITLE_LENGTH = 120;

    private const int LABEL_LENGTH = 40;

    /**
     * The imported calendars, newest first, as the page lists them.
     *
     * @return list<array<string, mixed>>
     */
    public static function forPage(Workspace $workspace): array
    {
        return CalendarImport::query()
            ->where('workspace_id', $workspace->id)
            ->imported()
            ->with('creator:id,name')
            ->latest()
            ->latest('id')
            ->get()
            ->map(self::summary(...))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function summary(CalendarImport $import): array
    {
        return [
            'id' => $import->id,
            'file_name' => $import->file_name,
            'rows_imported' => $import->rows_imported,
            'rows_skipped' => $import->rows_skipped,
            'skipped_reasons' => array_values($import->skipped_reasons ?? []),
            'first_date' => $import->first_date?->toDateString(),
            'last_date' => $import->last_date?->toDateString(),
            'created_by' => $import->creator?->name,
            'created_at' => $import->created_at?->toIso8601String(),
        ];
    }

    /**
     * An import's rows, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(CalendarImport $import): array
    {
        return $import->pastPosts()
            ->orderByDesc('posted_on')
            ->orderByDesc('row_number')
            ->limit(self::ROWS_SHOWN)
            ->get()
            ->map(fn (PastPost $post): array => [
                'id' => $post->id,
                'row' => $post->row_number,
                'date' => $post->posted_on->toDateString(),
                'platform' => $post->platform?->value,
                'channel' => $post->channel_label,
                'format' => $post->format,
                'title' => $post->title,
                'excerpt' => PastPerformance::excerpt($post->caption),
                'pillar' => $post->pillar,
                'engagement' => $post->engagement,
                'reach' => $post->reach,
                'views' => $post->views,
                'link' => $post->link,
            ])
            ->values()
            ->all();
    }

    /**
     * What the planner reads, up to 60 rows: every row from the plan's
     * calendar month in earlier years first (what the team did this time of
     * year), then the best by engagement, reach or views, then the newest.
     *
     * @return list<array<string, mixed>>
     */
    public static function forPlanner(string $workspaceId, CarbonImmutable $month): array
    {
        $base = fn (): Builder => PastPost::query()->where('workspace_id', $workspaceId);

        /** @var Collection<int, PastPost> $picked */
        $picked = $base()
            ->whereMonth('posted_on', $month->month)
            ->whereYear('posted_on', '<', $month->year)
            ->orderByDesc('posted_on')
            ->orderBy('id')
            ->limit(self::PLANNER_ROWS)
            ->get();

        foreach (['engagement', 'reach', 'views'] as $metric) {
            $room = self::PLANNER_ROWS - $picked->count();

            if ($room <= 0) {
                break;
            }

            $picked = $picked->concat($base()
                ->whereNotIn('id', $picked->modelKeys())
                ->whereNotNull($metric)
                ->orderByDesc($metric)
                ->orderBy('id')
                ->limit($room)
                ->get());
        }

        $room = self::PLANNER_ROWS - $picked->count();

        if ($room > 0) {
            $picked = $picked->concat($base()
                ->whereNotIn('id', $picked->modelKeys())
                ->orderByDesc('posted_on')
                ->orderByDesc('id')
                ->limit($room)
                ->get());
        }

        return $picked->map(fn (PastPost $post): array => array_filter([
            'date' => $post->posted_on->toDateString(),
            'platform' => $post->platform?->value ?? self::label($post->channel_label),
            'format' => $post->content_type?->value ?? self::label($post->format),
            'title' => self::label($post->title, self::TITLE_LENGTH),
            'pillar' => self::label($post->pillar),
            'excerpt' => PastPerformance::excerpt($post->caption),
            'engagement' => $post->engagement,
            'reach' => $post->reach,
            'views' => $post->views,
        ], fn (mixed $value): bool => $value !== null && $value !== ''))->values()->all();
    }

    private static function label(?string $text, int $length = self::LABEL_LENGTH): ?string
    {
        return blank($text) ? null : Str::of($text)->squish()->limit($length - 1, '…')->toString();
    }
}
