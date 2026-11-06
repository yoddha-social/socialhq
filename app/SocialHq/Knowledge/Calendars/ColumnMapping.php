<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Calendars;

use DateTimeInterface;
use Illuminate\Support\Str;

/**
 * Which column of an old calendar holds which field. guess() reads the
 * header (English, Nepali and romanised Nepali names); the person checks
 * it before importing. Only the date is required.
 */
final class ColumnMapping
{
    public const string DATE = 'date';

    /** Every field, in the order the mapping step shows them. */
    public const array FIELDS = ['date', 'channel', 'format', 'title', 'caption', 'pillar', 'notes', 'engagement', 'reach', 'views', 'link'];

    /**
     * Header names per field, normalised (lower case, punctuation as spaces).
     */
    private const array ALIASES = [
        'date' => ['date', 'dates', 'day', 'miti', 'मिति', 'गते', 'post date', 'posting date', 'publish date', 'publishing date', 'published on', 'posted on', 'date posted', 'date published', 'scheduled date', 'schedule date', 'scheduled', 'schedule', 'when', 'go live date', 'live date'],
        'channel' => ['channel', 'channels', 'platform', 'platforms', 'page', 'pages', 'network', 'networks', 'social', 'social media', 'social network', 'account', 'medium', 'madhyam', 'maadhyam', 'माध्यम', 'प्लेटफर्म', 'where'],
        'format' => ['format', 'formats', 'type', 'post type', 'content type', 'content format', 'post format', 'media type', 'kind', 'prakar', 'prakaar', 'प्रकार', 'ढाँचा'],
        'title' => ['title', 'topic', 'topics', 'idea', 'ideas', 'subject', 'headline', 'content idea', 'post idea', 'post title', 'concept', 'bishaya', 'bishay', 'vishay', 'vishaya', 'bisaya', 'विषय', 'shirshak', 'sirsak', 'शीर्षक'],
        'caption' => ['caption', 'captions', 'copy', 'post copy', 'caption copy', 'content', 'post', 'text', 'post text', 'description', 'message', 'body', 'script', 'copywriting', 'क्याप्सन', 'विवरण', 'bibaran', 'vivaran'],
        'pillar' => ['pillar', 'pillars', 'content pillar', 'theme', 'themes', 'category', 'categories', 'bucket', 'content bucket', 'series', 'campaign', 'श्रेणी', 'shreni'],
        'notes' => ['notes', 'note', 'objective', 'objectives', 'goal', 'goals', 'purpose', 'remarks', 'remark', 'comment', 'comments', 'cta', 'udeshya', 'uddeshya', 'उद्देश्य', 'kaifiyat', 'कैफियत', 'टिप्पणी'],
        'engagement' => ['engagement', 'engagements', 'total engagement', 'engagement count', 'likes', 'like', 'reactions', 'reaction', 'interactions', 'eng'],
        'reach' => ['reach', 'total reach', 'people reached', 'reached', 'accounts reached'],
        'views' => ['views', 'view', 'impressions', 'impression', 'plays', 'video views', 'views impressions'],
        'link' => ['link', 'links', 'url', 'post link', 'post url', 'permalink', 'live link'],
    ];

    /** Aliases too generic to match inside a longer header ("Link to post" is not the caption). */
    private const array EXACT_ONLY = ['post', 'content', 'type', 'page', 'pages', 'day', 'text', 'when', 'where', 'kind', 'eng', 'like', 'view', 'social', 'account', 'body', 'note', 'goal', 'schedule', 'scheduled', 'campaign', 'series'];

    /**
     * The field a header names exactly, or null.
     */
    public static function fieldFor(string $label): ?string
    {
        $normalised = self::normalise($label);

        foreach (self::ALIASES as $field => $aliases) {
            if (in_array($normalised, $aliases, true)) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Field => column index (null when the file has none). Exact header
     * names win, then headers that contain a name ("Date of post").
     *
     * @param  list<string>  $header
     * @return array<string, int|null>
     */
    public static function guess(array $header): array
    {
        $mapping = array_fill_keys(self::FIELDS, null);
        $normalised = array_map(self::normalise(...), $header);

        foreach ($normalised as $index => $label) {
            $field = self::fieldFor($label);

            if ($field !== null && $mapping[$field] === null) {
                $mapping[$field] = $index;
            }
        }

        foreach (self::FIELDS as $field) {
            if ($mapping[$field] !== null) {
                continue;
            }

            $aliases = array_values(array_diff(self::ALIASES[$field], self::EXACT_ONLY));
            usort($aliases, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

            foreach ($normalised as $index => $label) {
                if (in_array($index, $mapping, true) || $label === '') {
                    continue;
                }

                foreach ($aliases as $alias) {
                    if (str_contains(" {$label} ", " {$alias} ")) {
                        $mapping[$field] = $index;

                        continue 3;
                    }
                }
            }
        }

        return $mapping;
    }

    /**
     * The date order the date column suggests: a first number above 12
     * means day first, a second one above 12 month first. Without either,
     * day first, and not certain.
     *
     * @param  list<string|int|float|DateTimeInterface|null>  $values
     * @return array{order: DateOrder, certain: bool}
     */
    public static function guessDateOrder(array $values): array
    {
        $dayFirst = 0;
        $monthFirst = 0;

        foreach ($values as $value) {
            if (! is_string($value) || preg_match('/^\s*(\d{1,2})[\/.\-](\d{1,2})[\/.\-]\d{2,4}/', CalendarRow::asciiDigits($value), $parts) !== 1) {
                continue;
            }

            if ((int) $parts[1] > 12) {
                $dayFirst++;
            } elseif ((int) $parts[2] > 12) {
                $monthFirst++;
            }
        }

        return [
            'order' => $monthFirst > $dayFirst ? DateOrder::MonthFirst : DateOrder::DayFirst,
            'certain' => $dayFirst + $monthFirst > 0,
        ];
    }

    public static function normalise(string $label): string
    {
        return Str::of($label)
            ->lower()
            ->replaceMatches('/[_\-\/.:#()\[\]?*&|,]+/u', ' ')
            ->squish()
            ->toString();
    }
}
