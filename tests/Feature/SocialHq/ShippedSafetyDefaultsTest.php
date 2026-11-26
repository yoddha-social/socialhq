<?php

declare(strict_types=1);

use App\SocialHq\SocialHqServiceProvider;
use Laravel\Cashier\Cashier;

/**
 * What a fresh install gets when its env file doesn't mention a setting
 * (audit 2026-11-24, ARC-08). phpunit.xml turns several of these on again for
 * TryPost's own tests, so each test reads the config file with those
 * variables cleared.
 */

/**
 * The config file as it is shipped: the given variables cleared while it is read.
 *
 * @param  list<string>  $keys
 * @return array<string, mixed>
 */
function shippedConfig(string $file, array $keys): array
{
    $saved = collect($keys)->mapWithKeys(fn (string $key): array => [$key => [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null]]);

    foreach ($keys as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }

    try {
        return require config_path($file);
    } finally {
        foreach ($saved as $key => [$env, $envArray, $server]) {
            if ($env !== false) {
                putenv("{$key}={$env}");
            }
            if ($envArray !== null) {
                $_ENV[$key] = $envArray;
            }
            if ($server !== null) {
                $_SERVER[$key] = $server;
            }
        }
    }
}

test('only the networks we publish to are on unless an env variable turns another one on', function () {
    $networks = ['linkedin', 'linkedin-page', 'x', 'youtube', 'facebook', 'instagram-facebook', 'tiktok', 'instagram', 'threads', 'pinterest', 'bluesky', 'mastodon', 'telegram', 'discord', 'google_business'];
    $keys = ['LINKEDIN_ENABLED', 'LINKEDIN_PAGE_ENABLED', 'X_ENABLED', 'YOUTUBE_ENABLED', 'FACEBOOK_ENABLED', 'INSTAGRAM_FACEBOOK_ENABLED', 'TIKTOK_ENABLED', 'INSTAGRAM_ENABLED', 'THREADS_ENABLED', 'PINTEREST_ENABLED', 'BLUESKY_ENABLED', 'MASTODON_ENABLED', 'TELEGRAM_ENABLED', 'DISCORD_ENABLED', 'GOOGLE_BUSINESS_ENABLED', 'GOOGLE_DRIVE_ENABLED', 'CANVA_ENABLED'];

    $config = shippedConfig('trypost.php', $keys);
    $enabled = collect($networks)->filter(fn (string $network): bool => (bool) data_get($config, "platforms.{$network}.enabled"))->values()->all();

    expect($enabled)->toBe(['linkedin', 'linkedin-page', 'x', 'youtube', 'facebook', 'instagram-facebook'])
        ->and(data_get($config, 'media_sources.google_drive.enabled'))->toBeFalse()
        ->and(data_get($config, 'media_sources.canva.enabled'))->toBeFalse();
});

test('a missing MAIL_MAILER logs mail instead of sending it through TryPost\'s hosted service', function () {
    expect(shippedConfig('mail.php', ['MAIL_MAILER'])['default'])->toBe('log');
});

test('a self-hosted install doesn\'t register Cashier\'s Stripe webhook unless it is switched on', function () {
    expect(shippedConfig('socialhq.php', ['SOCIALHQ_STRIPE_WEBHOOK'])['billing']['stripe_webhook'])->toBeFalse();

    $registers = Cashier::$registersRoutes;

    try {
        config(['trypost.self_hosted' => true, 'socialhq.billing.stripe_webhook' => true]);
        (new SocialHqServiceProvider(app()))->register();
        expect(Cashier::$registersRoutes)->toBeTrue();

        config(['socialhq.billing.stripe_webhook' => false]);
        (new SocialHqServiceProvider(app()))->register();
        expect(Cashier::$registersRoutes)->toBeFalse();
    } finally {
        Cashier::$registersRoutes = $registers;
    }
});
