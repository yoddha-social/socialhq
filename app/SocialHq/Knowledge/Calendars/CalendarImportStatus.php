<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Calendars;

/**
 * Pending: uploaded, waiting for the column mapping (cleaned up after a day).
 * Imported: its rows are in shq_past_posts and the file is gone.
 */
enum CalendarImportStatus: string
{
    public const self DEFAULT = self::Pending;

    case Pending = 'pending';
    case Imported = 'imported';
}
