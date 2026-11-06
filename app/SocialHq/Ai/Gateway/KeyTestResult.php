<?php

declare(strict_types=1);

namespace App\SocialHq\Ai\Gateway;

/**
 * Whether a provider key answered a one-line request through LiteLLM. When
 * it didn't, errorKey says why in our words (a lang key under
 * socialhq_ai.errors); the provider's own text is never kept, since it may
 * echo part of the key.
 */
final readonly class KeyTestResult
{
    private function __construct(public bool $ok, public ?string $errorKey) {}

    public static function passed(): self
    {
        return new self(true, null);
    }

    public static function failed(string $errorKey): self
    {
        return new self(false, $errorKey);
    }

    /**
     * Sorts LiteLLM's error text into one of our reasons. The text is read
     * here and dropped.
     */
    public static function fromProviderError(string $error): self
    {
        $error = mb_strtolower($error);

        return self::failed(match (true) {
            str_contains($error, 'authenticationerror'),
            str_contains($error, 'invalid_api_key'),
            str_contains($error, 'invalid api key'),
            str_contains($error, 'api key not valid'),
            str_contains($error, '401') => 'invalid_key',
            str_contains($error, 'ratelimit'),
            str_contains($error, 'rate limit'),
            str_contains($error, 'quota'),
            str_contains($error, '429') => 'rate_limited',
            str_contains($error, 'permissiondenied'),
            str_contains($error, 'permission'),
            str_contains($error, 'notfounderror'),
            str_contains($error, '403'),
            str_contains($error, '404') => 'no_access',
            str_contains($error, 'timeout'),
            str_contains($error, 'timed out') => 'timeout',
            default => 'provider_error',
        });
    }
}
