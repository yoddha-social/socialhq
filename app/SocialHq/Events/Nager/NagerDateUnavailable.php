<?php

declare(strict_types=1);

namespace App\SocialHq\Events\Nager;

use RuntimeException;

/**
 * Nager.Date didn't answer, or answered something we can't read. `countries`
 * lists the ISO codes whose holidays couldn't be read (empty for the
 * country list itself).
 */
final class NagerDateUnavailable extends RuntimeException
{
    /**
     * @param  list<string>  $countries
     */
    public function __construct(public readonly array $countries = [], string $message = 'Nager.Date is unavailable.')
    {
        parent::__construct($message);
    }
}
