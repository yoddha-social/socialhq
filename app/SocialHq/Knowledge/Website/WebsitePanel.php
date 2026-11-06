<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Website;

use App\Models\Workspace;
use App\SocialHq\Ai\AiRouter;
use App\SocialHq\Ai\AiTask;

/**
 * The `website` prop of the Brand knowledge page: the latest run and its
 * suggestions, the address to read again and whether the AI is available.
 */
final class WebsitePanel
{
    /**
     * @return array<string, mixed>
     */
    public static function prop(Workspace $workspace): array
    {
        $crawl = WebsiteCrawl::latestFor($workspace);

        return [
            'aiAvailable' => AiRouter::isAvailable(AiTask::Structured),
            'maxPages' => (int) config('socialhq.knowledge.website.max_pages', 25),
            'crawl' => $crawl === null ? null : [
                'id' => $crawl->id,
                'url' => $crawl->url,
                // A run whose worker died is shown as failed after half an hour.
                'status' => $crawl->status->isRunning() && $crawl->created_at?->lt(now()->subMinutes(30)) ? CrawlStatus::Failed->value : $crawl->status->value,
                'failure' => $crawl->failure?->value ?? ($crawl->status->isRunning() && $crawl->created_at?->lt(now()->subMinutes(30)) ? CrawlFailure::Unreachable->value : null),
                'pages_read' => $crawl->pages_read,
                'pages_unchanged' => $crawl->pages_unchanged,
                'pages_skipped' => $crawl->pages_skipped,
                'finished_at' => $crawl->finished_at?->toIso8601String(),
                'suggestions' => $crawl->status->hasSuggestions() ? array_values((array) $crawl->suggestions) : [],
            ],
        ];
    }
}
