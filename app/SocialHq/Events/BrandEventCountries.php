<?php

declare(strict_types=1);

namespace App\SocialHq\Events;

use App\Models\Workspace;
use App\SocialHq\Knowledge\Audience\Audience;
use App\SocialHq\Knowledge\BrandProfile;
use App\SocialHq\Plan\PlanOptions;

/**
 * The countries whose dates a brand plans around (owner decision 2026-11-24):
 * a brand abroad never sees Nepal's festivals unless it follows Nepal. The
 * brand's own choice, else its audience's countries, else Nepal (the dataset
 * Social HQ started with). International days and the brand's own dates are
 * always in; a date a brand hid is out (`CalendarEvent::scopeForBrand`).
 */
final class BrandEventCountries
{
    /** At most this many countries per brand. */
    public const MAX = 20;

    /**
     * @return list<string>
     */
    public static function for(Workspace $workspace, ?BrandProfile $profile = null): array
    {
        $profile ??= PlanOptions::profile($workspace);

        return self::chosen($profile)
            ?? (self::normalize(Audience::countries($profile)) ?: [CalendarEvent::NEPAL]);
    }

    /**
     * The brand's own choice, or null while it follows its audience.
     *
     * @return list<string>|null
     */
    public static function chosen(?BrandProfile $profile): ?array
    {
        $codes = $profile?->event_countries;

        return is_array($codes) ? self::normalize($codes) : null;
    }

    public static function follows(Workspace $workspace, string $country): bool
    {
        return in_array(strtoupper($country), self::for($workspace), true);
    }

    /**
     * Saves the brand's choice.
     *
     * @param  list<string>  $countries
     */
    public static function save(Workspace $workspace, array $countries): void
    {
        BrandProfile::query()->updateOrCreate(['workspace_id' => $workspace->id], ['event_countries' => self::normalize($countries)]);
    }

    /**
     * Adds countries to what the brand follows (a country whose holidays
     * someone just imported from this brand's page).
     *
     * @param  list<string>  $countries
     */
    public static function add(Workspace $workspace, array $countries): void
    {
        self::save($workspace, [...self::for($workspace), ...$countries]);
    }

    /**
     * @param  array<mixed>  $codes
     * @return list<string>
     */
    private static function normalize(array $codes): array
    {
        return collect($codes)
            ->filter(fn (mixed $code): bool => is_string($code))
            ->map(fn (string $code): string => strtoupper(trim($code)))
            ->filter(fn (string $code): bool => preg_match('/^[A-Z]{2}$/', $code) === 1)
            ->unique()
            ->take(self::MAX)
            ->values()
            ->all();
    }
}
