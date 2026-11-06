<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Audience;

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Plan\PlanOptions;
use App\Support\Timezone;

/**
 * The `audience` prop of Brand knowledge: the saved places, what the form
 * can pick from (countries with their main zone, every time zone) and the
 * main zone the plan uses.
 */
final class AudiencePage
{
    /**
     * @return array{
     *     locations: list<array{country: string, city: string|null, timezone: string, share: int}>,
     *     mainTimezone: string,
     *     countries: list<array{code: string, name: string, timezone: string}>,
     *     timezones: list<array{value: string, label: string, offset: string}>,
     *     maxLocations: int,
     * }
     */
    public static function prop(Workspace $workspace, User $user): array
    {
        $profile = PlanOptions::profile($workspace);

        return [
            'locations' => Audience::locations($profile),
            'mainTimezone' => Audience::mainZone($profile, $user->timezone),
            'countries' => AudienceCountries::all(),
            'timezones' => Timezone::options(),
            'maxLocations' => Audience::MAX_LOCATIONS,
        ];
    }
}
