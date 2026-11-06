<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Calendars;

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Str;

/**
 * Reads the cells of an old calendar: dates, counts, channels and formats,
 * as people type them in a spreadsheet.
 *
 * Dates are read, never converted between calendars: a Bikram Sambat date
 * is refused with a reason, not turned into an AD date.
 */
final class CalendarRow
{
    /** Years that look like Bikram Sambat (2070 BS is 2013 AD). */
    public const int BIKRAM_SAMBAT_FROM = 2070;

    public const int BIKRAM_SAMBAT_TO = 2099;

    /** Older dates are not read as calendar dates. */
    public const int EARLIEST_YEAR = 2000;

    private const int MAX_COUNT = 4_294_967_295;

    private const array MONTHS = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december'];

    /**
     * Channel names per network, normalised.
     */
    private const array NETWORKS = [
        'facebook' => ['facebook', 'fb', 'facebook page', 'fb page', 'meta'],
        'instagram' => ['instagram', 'insta', 'ig'],
        'linkedin' => ['linkedin', 'linked in', 'li'],
        'youtube' => ['youtube', 'yt', 'you tube'],
        'x' => ['x', 'twitter', 'tweet', 'x twitter'],
        'tiktok' => ['tiktok', 'tik tok', 'tt'],
        'threads' => ['threads'],
        'pinterest' => ['pinterest'],
        'bluesky' => ['bluesky', 'bsky'],
        'mastodon' => ['mastodon'],
        'telegram' => ['telegram'],
        'discord' => ['discord'],
        'google_business' => ['google business', 'google business profile', 'google my business', 'gmb', 'gbp'],
    ];

    /**
     * Format words per network. A format is recognised only when its words
     * point to one content type of the network ("Carousel video" does not).
     */
    private const array FORMATS = [
        'instagram' => [
            'reel' => 'instagram_reel', 'reels' => 'instagram_reel', 'video' => 'instagram_reel',
            'story' => 'instagram_story', 'stories' => 'instagram_story',
            'post' => 'instagram_feed', 'feed' => 'instagram_feed', 'image' => 'instagram_feed', 'photo' => 'instagram_feed',
            'carousel' => 'instagram_feed', 'static' => 'instagram_feed', 'graphic' => 'instagram_feed', 'single image' => 'instagram_feed',
        ],
        'facebook' => [
            'reel' => 'facebook_reel', 'reels' => 'facebook_reel',
            'story' => 'facebook_story', 'stories' => 'facebook_story',
            'post' => 'facebook_post', 'image' => 'facebook_post', 'photo' => 'facebook_post', 'carousel' => 'facebook_post',
            'album' => 'facebook_post', 'static' => 'facebook_post', 'graphic' => 'facebook_post', 'text' => 'facebook_post',
            'link' => 'facebook_post', 'status' => 'facebook_post',
        ],
        'tiktok' => [
            'video' => 'tiktok_video', 'reel' => 'tiktok_video',
            'photo' => 'tiktok_photo', 'photos' => 'tiktok_photo', 'carousel' => 'tiktok_photo', 'slideshow' => 'tiktok_photo', 'image' => 'tiktok_photo',
        ],
        'youtube' => ['short' => 'youtube_short', 'shorts' => 'youtube_short'],
        'threads' => [
            'post' => 'threads_post', 'text' => 'threads_post', 'image' => 'threads_post', 'photo' => 'threads_post',
            'carousel' => 'threads_post', 'video' => 'threads_post', 'ghost post' => 'threads_ghost_post',
        ],
        'pinterest' => [
            'pin' => 'pinterest_pin', 'image' => 'pinterest_pin', 'photo' => 'pinterest_pin', 'static' => 'pinterest_pin', 'graphic' => 'pinterest_pin',
            'video' => 'pinterest_video_pin', 'video pin' => 'pinterest_video_pin', 'idea pin' => 'pinterest_video_pin',
            'carousel' => 'pinterest_carousel',
        ],
    ];

    /**
     * A calendar day, or why the cell isn't one.
     */
    public static function date(string|int|float|DateTimeInterface|null $value, DateOrder $order, CarbonImmutable $today): CarbonImmutable|SkipReason
    {
        $parts = self::dateParts($value, $order);

        if ($parts instanceof SkipReason) {
            return $parts;
        }

        [$year, $month, $day] = $parts;

        // Checked before the day is: Bikram Sambat months have up to 32 days.
        if ($year >= self::BIKRAM_SAMBAT_FROM && $year <= self::BIKRAM_SAMBAT_TO) {
            return SkipReason::BikramSambat;
        }

        if ($year < self::EARLIEST_YEAR || ! checkdate($month, $day, $year)) {
            return SkipReason::UnreadableDate;
        }

        $date = CarbonImmutable::create($year, $month, $day, 0, 0, 0, 'UTC');

        return $date->greaterThan($today->startOfDay()) ? SkipReason::FutureDate : $date;
    }

    /**
     * A count (likes, reach, views): "1,234", "1.2k", "3M", or a number cell.
     */
    public static function count(string|int|float|DateTimeInterface|null $value): ?int
    {
        if (is_int($value) || is_float($value)) {
            return $value < 0 ? null : (int) min(self::MAX_COUNT, round($value));
        }

        if (! is_string($value)) {
            return null;
        }

        $text = Str::of(self::asciiDigits($value))->lower()->replace([' ', "\u{00A0}", ','], '')->rtrim('+')->toString();

        // "1.234.567": dots as thousands separators.
        if (preg_match('/^\d{1,3}(\.\d{3})+$/', $text) === 1) {
            $text = str_replace('.', '', $text);
        }

        if (preg_match('/^(\d+(?:\.\d+)?)([km])?$/', $text, $match) !== 1) {
            return null;
        }

        $multiplier = match ($match[2] ?? '') {
            'k' => 1_000,
            'm' => 1_000_000,
            default => 1,
        };

        return (int) min(self::MAX_COUNT, round((float) $match[1] * $multiplier));
    }

    /**
     * The one network a channel cell names ("FB", "Instagram Reel"), or
     * null when it names none or several ("FB + IG").
     */
    public static function network(?string $channel): ?string
    {
        if (blank($channel)) {
            return null;
        }

        $found = [];

        foreach (preg_split('/\s*(?:[,\/&+;|]|\band\b)\s*/u', ColumnMapping::normalise($channel)) ?: [] as $part) {
            foreach (self::NETWORKS as $network => $names) {
                foreach ($names as $name) {
                    if (str_contains(" {$part} ", " {$name} ")) {
                        $found[$network] = true;
                    }
                }
            }
        }

        return count($found) === 1 ? array_key_first($found) : null;
    }

    /**
     * The platform for a network: the one the workspace connected for it,
     * else the network's usual platform.
     *
     * @param  array<string, Platform>  $connected  Network => the workspace's platform for it
     */
    public static function platform(?string $network, ?string $channel, array $connected): ?Platform
    {
        if ($network === null) {
            return null;
        }

        if (isset($connected[$network])) {
            return $connected[$network];
        }

        if ($network === 'linkedin') {
            return preg_match('/\b(page|company)\b/i', (string) $channel) === 1 ? Platform::LinkedInPage : Platform::LinkedIn;
        }

        return Platform::tryFrom($network);
    }

    /**
     * The content type a format names on a platform, only when it is clear.
     */
    public static function contentType(?string $format, ?Platform $platform): ?ContentType
    {
        if ($platform === null || blank($format)) {
            return null;
        }

        $types = array_values(ContentType::forPlatform($platform));
        $words = self::FORMATS[$platform->network()] ?? null;

        // Networks with one content type (X, LinkedIn, Bluesky...) take it for any format.
        if ($words === null) {
            return count($types) === 1 ? $types[0] : null;
        }

        $text = ' '.ColumnMapping::normalise($format).' ';
        $found = [];
        uksort($words, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($words as $word => $type) {
            if (str_contains($text, " {$word} ")) {
                $found[$type] = true;
                $text = str_replace(" {$word} ", ' ', $text);
            }
        }

        if (count($found) !== 1) {
            return null;
        }

        $type = ContentType::tryFrom((string) array_key_first($found));

        return $type !== null && in_array($type, $types, true) ? $type : null;
    }

    /**
     * An http(s) link, or null.
     */
    public static function link(?string $value): ?string
    {
        $value = trim((string) $value);

        if (mb_strlen($value) > 2048 || preg_match('#^https?://[^\s]+$#i', $value) !== 1 || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $value;
    }

    /**
     * Devanagari digits (०-९) as ASCII digits.
     */
    public static function asciiDigits(string $text): string
    {
        return strtr($text, ['०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4', '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9']);
    }

    /**
     * Year, month and day as written, not yet checked.
     *
     * @return array{0: int, 1: int, 2: int}|SkipReason
     */
    private static function dateParts(string|int|float|DateTimeInterface|null $value, DateOrder $order): array|SkipReason
    {
        if ($value instanceof DateTimeInterface) {
            return [(int) $value->format('Y'), (int) $value->format('n'), (int) $value->format('j')];
        }

        if (is_int($value) || is_float($value)) {
            return self::serialParts($value);
        }

        $text = trim(self::asciiDigits((string) $value));

        if ($text === '') {
            return SkipReason::NoDate;
        }

        // A leading weekday ("Fri, 3 Oct 2025") says nothing the date doesn't.
        $text = (string) preg_replace('/^(mon|tue|wed|thu|fri|sat|sun)[a-z]*\.?,?\s+/i', '', $text);

        if (preg_match('/^\d{5}(\.\d+)?$/', $text) === 1) {
            return self::serialParts((float) $text);
        }

        if (preg_match('/^(\d{4})[\-\/.](\d{1,2})[\-\/.](\d{1,2})(?![\d])/', $text, $match) === 1) {
            return [(int) $match[1], (int) $match[2], (int) $match[3]];
        }

        if (preg_match('/^(\d{1,2})[\-\/.](\d{1,2})[\-\/.](\d{4}|\d{2})(?![\d])/', $text, $match) === 1) {
            $year = self::fullYear($match[3]);

            return $order === DateOrder::DayFirst
                ? [$year, (int) $match[2], (int) $match[1]]
                : [$year, (int) $match[1], (int) $match[2]];
        }

        // "3 Oct 2025", "3rd October, 2025", "03-Oct-25"
        if (preg_match('/^(\d{1,2})(?:st|nd|rd|th)?[\s\-\/.]+([a-z]{3,9})\.?,?[\s\-\/.]+(\d{4}|\d{2})(?![\d])/i', $text, $match) === 1) {
            $month = self::month($match[2]);

            return $month === null ? SkipReason::UnreadableDate : [self::fullYear($match[3]), $month, (int) $match[1]];
        }

        // "Oct 3, 2025", "October 3rd 2025"
        if (preg_match('/^([a-z]{3,9})\.?[\s\-]+(\d{1,2})(?:st|nd|rd|th)?,?[\s\-]+(\d{4})(?![\d])/i', $text, $match) === 1) {
            $month = self::month($match[1]);

            return $month === null ? SkipReason::UnreadableDate : [(int) $match[3], $month, (int) $match[2]];
        }

        return SkipReason::UnreadableDate;
    }

    /**
     * An Excel date serial: days since 30 December 1899.
     *
     * @return array{0: int, 1: int, 2: int}|SkipReason
     */
    private static function serialParts(float|int $serial): array|SkipReason
    {
        if ($serial < 1 || $serial > 2_958_465) {
            return SkipReason::UnreadableDate;
        }

        $date = CarbonImmutable::create(1899, 12, 30, 0, 0, 0, 'UTC')->addDays((int) floor($serial));

        return [$date->year, $date->month, $date->day];
    }

    private static function fullYear(string $year): int
    {
        return strlen($year) === 2 ? 2000 + (int) $year : (int) $year;
    }

    private static function month(string $name): ?int
    {
        $name = strtolower($name);

        foreach (self::MONTHS as $index => $month) {
            if (str_starts_with($month, $name)) {
                return $index + 1;
            }
        }

        return null;
    }
}
