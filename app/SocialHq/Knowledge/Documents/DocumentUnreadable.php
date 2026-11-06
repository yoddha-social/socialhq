<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Documents;

use RuntimeException;

/**
 * Thrown by DocumentText when a file can't be read; carries the reason.
 */
final class DocumentUnreadable extends RuntimeException
{
    public function __construct(public readonly DocumentFailure $failure)
    {
        parent::__construct("Knowledge document unreadable: {$failure->value}");
    }
}
