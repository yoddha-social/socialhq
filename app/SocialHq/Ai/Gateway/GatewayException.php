<?php

declare(strict_types=1);

namespace App\SocialHq\Ai\Gateway;

use RuntimeException;

/**
 * LiteLLM's admin API failed. The message names the route and the status
 * only, never a request or response body (they can hold keys); errorKey is
 * the lang key under socialhq_ai.errors the owner sees.
 */
class GatewayException extends RuntimeException
{
    public function __construct(public readonly string $errorKey, string $message)
    {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self('gateway_not_configured', 'LiteLLM admin key (LITELLM_ADMIN_KEY) is not set.');
    }
}
