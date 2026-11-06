<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Website;

use RuntimeException;

/**
 * Thrown by WebsiteCrawler when a run can't go on; carries the reason.
 */
final class CrawlStopped extends RuntimeException
{
    public function __construct(public readonly CrawlFailure $failure)
    {
        parent::__construct("Website crawl stopped: {$failure->value}");
    }
}
