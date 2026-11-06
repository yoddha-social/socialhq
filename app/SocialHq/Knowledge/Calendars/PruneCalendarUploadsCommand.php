<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Calendars;

use Illuminate\Console\Command;

/**
 * Deletes old calendar uploads that were never imported (the dialog was
 * closed, the browser went away) once they are a day old, with their files.
 */
class PruneCalendarUploadsCommand extends Command
{
    protected $signature = 'socialhq:calendars:prune';

    protected $description = 'Delete old calendar uploads never imported, after a day';

    public function handle(): int
    {
        $count = 0;

        CalendarImport::query()
            ->pending()
            ->where('created_at', '<', now()->subDay())
            ->lazyById()
            ->each(function (CalendarImport $import) use (&$count): void {
                $import->delete();
                $count++;
            });

        $this->info("Deleted {$count} abandoned calendar uploads.");

        return self::SUCCESS;
    }
}
