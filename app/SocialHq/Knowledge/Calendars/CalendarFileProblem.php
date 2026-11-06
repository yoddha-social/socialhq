<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Calendars;

use RuntimeException;

/**
 * A file that can't be imported as a calendar, with the message the person
 * uploading it sees (a socialhq_calendars.errors.* key).
 */
final class CalendarFileProblem extends RuntimeException
{
    /**
     * @param  array<string, string|int>  $replace
     */
    public function __construct(public readonly string $key, public readonly array $replace = [])
    {
        parent::__construct($key);
    }

    public function userMessage(): string
    {
        return __("socialhq_calendars.errors.{$this->key}", array_map(strval(...), $this->replace));
    }
}
