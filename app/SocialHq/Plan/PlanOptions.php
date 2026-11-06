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
     * Events the brand sees that overlap the days given, confirmed or not, in
     * date order. With countries, only the events of those countries and the
     * international ones (no country).
     *
     * @param  list<string>|null  $countries  ISO 3166-1 alpha-2 codes, or null for every event
     * @return Collection<int, CalendarEvent>
     */
    public static function events(Workspace $workspace, CarbonImmutable $from, CarbonImmutable $to, ?array $countries = null): Collection
    {
        $events = self::eventsBetween($workspace, $from, $to);

        if ($countries === null) {
            return $events;
        }

        return $events
            ->filter(fn (CalendarEvent $event): bool => $event->country_code === null || in_array($event->country_code, $countries, true))
            ->values();
    }

    public static function profile(Workspace $workspace): ?BrandProfile
    {
        return BrandProfile::query()->where('workspace_id', $workspace->id)->first();
    }

    /**
     * The brand's pillars, at most MAX_PILLARS (the settings form enforces it; this keeps the prompt bounded too).
     *
     * @return list<string>
     */
    public static function pillars(?BrandProfile $profile): array
    {
        return array_slice(array_values(array_filter(
            array_map(fn (mixed $pillar): string => trim((string) $pillar), (array) ($profile?->pillars ?? [])),
            fn (string $pillar): bool => $pillar !== '' && mb_strlen($pillar) <= self::MAX_PILLAR_LENGTH,
        )), 0, self::MAX_PILLARS);
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
     * Whether what has to be made can go out in this format: a video needs a
     * format that takes video, an image or a carousel one that takes images
     * (a carousel several), a document one that takes documents, and "none"
     * a format that publishes without media.
     */
    public static function creativeFits(CreativeKind $creative, ContentType $contentType): bool
    {
        return match ($creative) {
            CreativeKind::Video => $contentType->supportsVideo(),
            CreativeKind::Image => $contentType->supportsImage(),
            CreativeKind::Carousel => $contentType->supportsImage() && $contentType->maxMediaCount() > 1,
            CreativeKind::Document => $contentType->supportsDocument(),
            CreativeKind::None => ! $contentType->requiresMedia(),
        };
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
