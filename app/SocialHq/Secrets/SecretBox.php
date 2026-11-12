<?php

declare(strict_types=1);

namespace App\SocialHq\Secrets;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Encrypts the secrets Social HQ keeps for companies (a company's LiteLLM
 * virtual key, later its social app credentials) with SOCIALHQ_SECRETS_KEY,
 * never APP_KEY: a leaked APP_KEY (cookies, signed URLs) must not open them,
 * and the key can be rotated on its own (SOCIALHQ_SECRETS_PREVIOUS_KEYS keeps
 * decrypting what the old key wrote until it is re-encrypted).
 *
 * Without the key nothing is saved: callers check isConfigured() first and
 * encrypt() throws SecretsKeyMissing.
 */
final class SecretBox
{
    private const CIPHER = 'AES-256-GCM';

    public static function isConfigured(): bool
    {
        return self::key((string) config('socialhq.secrets.key')) !== null;
    }

    /**
     * @throws SecretsKeyMissing
     */
    public static function encrypt(#[SensitiveParameter] string $value): string
    {
        return self::encrypter()->encryptString($value);
    }

    /**
     * @throws SecretsKeyMissing
     * @throws DecryptException when no current or previous key opens it
     */
    public static function decrypt(string $payload): string
    {
        return self::encrypter()->decryptString($payload);
    }

    /**
     * @throws SecretsKeyMissing
     */
    private static function encrypter(): Encrypter
    {
        $key = self::key((string) config('socialhq.secrets.key'));

        if ($key === null) {
            throw new SecretsKeyMissing;
        }

        $previous = array_values(array_filter(array_map(
            fn (mixed $previousKey): ?string => self::key((string) $previousKey),
            Arr::wrap(config('socialhq.secrets.previous_keys', [])),
        )));

        return (new Encrypter($key, self::CIPHER))->previousKeys($previous);
    }

    /**
     * The raw 32 bytes of a "base64:..." key, or null when it is missing,
     * the wrong length, or the same as APP_KEY.
     */
    private static function key(string $configured): ?string
    {
        $configured = trim($configured);

        if ($configured === '' || $configured === trim((string) config('app.key'))) {
            return null;
        }

        $raw = Str::startsWith($configured, 'base64:') ? base64_decode(Str::after($configured, 'base64:'), true) : false;

        return is_string($raw) && strlen($raw) === 32 ? $raw : null;
    }
}
