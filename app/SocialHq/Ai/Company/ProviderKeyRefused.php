<?php

declare(strict_types=1);

namespace App\SocialHq\Ai\Company;

use RuntimeException;

/**
 * A provider key failed its test, so it was not saved. errorKey is the
 * reason under socialhq_ai.errors.
 */
class ProviderKeyRefused extends RuntimeException
{
    public function __construct(public readonly string $errorKey)
    {
        parent::__construct("The provider key failed its test ({$errorKey}).");
    }
}
