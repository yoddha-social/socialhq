<?php

declare(strict_types=1);

namespace App\SocialHq\Plan;

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Events\CalendarEvent;
use App\SocialHq\Events\EventsForMonth;
use App\SocialHq\Knowledge\BrandProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What a brand's plan can be made of: its connected channels, the people who
 * may own an item, the events of a month, and the pillars and posting cadence
 * on the brand profile. Shared by the month page, the item checks and the
 * AI planner, so all three agree.
 */
final class PlanOptions
{
    public const MAX_PILLARS = 8;

    public const MAX_PILLAR_LENGTH = 40;

    public const MAX_POSTS_PER_WEEK = 14;

    /**
     * Channels a new item can go to: the workspace's connected accounts, in the sidebar's order.
     *
     * @return Collection<int, SocialAccount>
     */
    public static function channels(Workspace $workspace): Collection
    {
        return $workspace->socialAccounts()->connected()->get();
    }

    /**
     * Who may own an item: the members plus the account owner (as Workspace::approvers() unions them).
     *
     * @return Collection<int, User>
     */
    public static function owners(Workspace $workspace): Collection
    {
        $people = $workspace->members()->orderBy('name')->get();
        $owner = $workspace->account?->owner;

        if ($owner !== null && ! $people->contains('id', $owner->id)) {
            $people->push($owner);
        }

        return $people->values();
    }

    /**
     * Events the brand sees that overlap the days given, confirmed or not, in date order.
     *
     * @return Collection<int, CalendarEvent>
     */
    public static function events(Workspace $workspace, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return self::eventsBetween($workspace, $from, $to);
    }

    public static function profile(Workspace $workspace): ?BrandProfile
    {
        return BrandProfile::query()->where('workspace_id', $workspace->id)->first();
    }

    /**
     * @return list<string>
     */
    public static function pillars(?BrandProfile $profile): array
    {
        return array_values(array_filter(
            array_map(fn (mixed $pillar): string => trim((string) $pillar), (array) ($profile?->pillars ?? [])),
            fn (string $pillar): bool => $pillar !== '',
        ));
    }

    /**
     * Posts per week per channel id, for the given channels only: null when
     * the brand hasn't set it, 0 when the channel should get no posts.
     *
     * @param  Collection<int, SocialAccount>  $channels
     * @return array<string, int|null>
     */
    public static function cadence(?BrandProfile $profile, Collection $channels): array
    {
        $saved = (array) ($profile?->cadence ?? []);

        return $channels->mapWithKeys(function (SocialAccount $channel) use ($saved): array {
            $count = data_get($saved, $channel->id);

            return [$channel->id => is_numeric($count) ? max(0, min(self::MAX_POSTS_PER_WEEK, (int) $count)) : null];
        })->all();
    }

    /**
     * The formats a channel's platform offers (Instagram through Facebook gets Instagram's).
     *
     * @return list<ContentType>
     */
    public static function contentTypesFor(Platform $platform): array
    {
        return array_values(ContentType::forPlatform($platform));
    }

    public static function fitsChannel(ContentType $contentType, Platform $platform): bool
    {
        return in_array($contentType, ContentType::forPlatform($platform), true);
    }

    /**
     * The brand's events overlapping the range (shared and brand-only), oldest first.
     *
     * @return Collection<int, CalendarEvent>
     */
    private static function eventsBetween(Workspace $workspace, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return EventsForMonth::forWorkspace($workspace, $from, $to);
    }
}
