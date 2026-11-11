<?php

declare(strict_types=1);

namespace App\SocialHq\Secrets;

use RuntimeException;

/**
 * SOCIALHQ_SECRETS_KEY is not set (or is not a base64:32-byte key, or is the
 * same as APP_KEY), so no company secret can be saved or read.
 */
class SecretsKeyMissing extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('SOCIALHQ_SECRETS_KEY is not set: generate one with `php artisan socialhq:secrets-key` and add it to the environment.');
    }
}
