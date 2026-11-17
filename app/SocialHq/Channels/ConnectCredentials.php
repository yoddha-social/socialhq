<?php

declare(strict_types=1);

namespace App\SocialHq\Channels;

use App\Enums\SocialAccount\Platform;

/**
 * Whether this server holds what a network's connect flow needs: the OAuth
 * app's client id and secret, or the shared bot for Telegram and Discord.
 * Bluesky and Mastodon sign in with the person's own details and need none.
 *
 * A network without them is not offered (connect dialog, sidebar shortcuts)
 * and its connect route refuses, instead of sending people to the network's
 * own error page. Config reads only, so it is cheap on every page.
 * `socialhq.channels.require_credentials` turns the check off (upstream tests).
 */
final class ConnectCredentials
{
    public static function configured(Platform $platform): bool
    {
        if (! config('socialhq.channels.require_credentials', true)) {
            return true;
        }

        return match ($platform) {
            Platform::LinkedIn, Platform::LinkedInPage => self::filled('services.linkedin.client_id', 'services.linkedin.client_secret'),
            Platform::X => self::filled('services.x.client_id', 'services.x.client_secret'),
            Platform::TikTok => self::filled('services.tiktok.client_id', 'services.tiktok.client_secret'),
            Platform::YouTube => self::filled('services.google.client_id', 'services.google.client_secret'),
            Platform::Facebook, Platform::InstagramFacebook => self::filled('services.facebook.client_id', 'services.facebook.client_secret'),
            Platform::Instagram => self::filled('services.instagram.client_id', 'services.instagram.client_secret'),
            Platform::Threads => self::filled('services.threads.client_id', 'services.threads.client_secret'),
            Platform::Pinterest => self::filled('services.pinterest.client_id', 'services.pinterest.client_secret'),
            Platform::Discord => self::filled('services.discord.client_id', 'services.discord.client_secret', 'trypost.platforms.discord.bot_token'),
            Platform::GoogleBusiness => self::filled('services.google-business.client_id', 'services.google-business.client_secret'),
            Platform::Telegram => self::filled('trypost.platforms.telegram.bot_token', 'trypost.platforms.telegram.bot_username'),
            Platform::Bluesky, Platform::Mastodon => true,
        };
    }

    private static function filled(string ...$keys): bool
    {
        foreach ($keys as $key) {
            if (blank(config($key))) {
                return false;
            }
        }

        return true;
    }
}
