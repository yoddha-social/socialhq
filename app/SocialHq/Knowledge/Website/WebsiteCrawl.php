<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Website;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One "Read our website" run: the site it read, how it went, and what the
 * AI suggested (WebsiteSuggestions), kept until an admin decides on each.
 * The row is also the log of the attempt (who, provider, model, status).
 */
class WebsiteCrawl extends Model
{
    protected $table = 'shq_website_crawls';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => CrawlStatus::class,
            'failure' => CrawlFailure::class,
            'suggestions' => 'array',
            'pages_read' => 'integer',
            'pages_unchanged' => 'integer',
            'pages_skipped' => 'integer',
            'characters' => 'integer',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public static function latestFor(Workspace $workspace): ?self
    {
        return self::query()->where('workspace_id', $workspace->id)->latest('id')->first();
    }

    /**
     * A run still going in this workspace, started in the last half hour
     * (an older one has died with its worker).
     */
    public static function runningIn(Workspace $workspace): ?self
    {
        return self::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('status', [CrawlStatus::Queued->value, CrawlStatus::Reading->value, CrawlStatus::Drafting->value])
            ->where('created_at', '>=', now()->subMinutes(30))
            ->latest('id')
            ->first();
    }
}
