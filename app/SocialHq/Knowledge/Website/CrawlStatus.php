<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Website;

/**
 * Where a "Read our website" run is.
 */
enum CrawlStatus: string
{
    case Queued = 'queued';
    case Reading = 'reading';
    case Drafting = 'drafting';
    /** Suggestions are waiting for review (or all decided). */
    case Ready = 'ready';
    /** Nothing changed since the last run: no AI call, the last suggestions carried over. */
    case Unchanged = 'unchanged';
    case Failed = 'failed';

    public function isRunning(): bool
    {
        return in_array($this, [self::Queued, self::Reading, self::Drafting], true);
    }

    public function hasSuggestions(): bool
    {
        return in_array($this, [self::Ready, self::Unchanged], true);
    }
}
