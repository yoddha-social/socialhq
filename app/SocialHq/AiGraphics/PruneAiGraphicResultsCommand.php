<?php

declare(strict_types=1);

namespace App\SocialHq\AiGraphics;

use Illuminate\Console\Command;

/**
 * Deletes our copies of generated graphics once they are older than
 * socialhq.ai_graphics.keep_results_days (the uploads made from them stay).
 */
class PruneAiGraphicResultsCommand extends Command
{
    protected $signature = 'socialhq:ai-graphics:prune';

    protected $description = 'Delete stored AI graphic results older than the configured days';

    public function handle(): int
    {
        $count = AiGraphicResults::prune();

        $this->info("Deleted {$count} old AI graphic results.");

        return self::SUCCESS;
    }
}
