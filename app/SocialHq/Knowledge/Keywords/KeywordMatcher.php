<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Keywords;

use Normalizer;

/**
 * Whether a text says an SEO keyword: its words in order, case and accents
 * ignored, with small linking words allowed between them ("specialty coffee
 * in Kathmandu" says "specialty coffee kathmandu"). A hashtag counts when it
 * is the keyword's words run together (#SpecialtyCoffeeKathmandu). Whole
 * words only: "brewing" doesn't say "brew".
 *
 * One rule for the keyword check, "recent posts" and "keywords used".
 */
final class KeywordMatcher
{
    /** Words a keyword may have, or a text may add, between its other words. */
    public const array LINKING_WORDS = ['a', 'an', 'at', 'for', 'from', 'in', 'of', 'the'];

    /** At most this many linking words in a row between two of the keyword's words. */
    private const int MAX_GAP = 2;

    /**
     * The words of a text: lowercased, accents on Latin letters dropped
     * (Devanagari keeps its vowel signs), a hashtag read as one word.
     *
     * @return list<string>
     */
    public static function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{M}\p{N}]+/u', self::fold($text), $matches);

        return array_values($matches[0]);
    }

    /**
     * Lowercased, with the accents of Latin letters dropped ("Café" is
     * "cafe"); other scripts' marks stay.
     */
    public static function fold(string $text): string
    {
        $text = mb_strtolower($text);
        $decomposed = Normalizer::normalize($text, Normalizer::FORM_D);

        if (! is_string($decomposed)) {
            return $text;
        }

        return (string) Normalizer::normalize((string) preg_replace('/[\x{0300}-\x{036F}]/u', '', $decomposed), Normalizer::FORM_C);
    }

    /**
     * The keyword's words without its linking words (all of them when it has
     * nothing else).
     *
     * @return list<string>
     */
    public static function coreWords(string $keyword): array
    {
        $words = self::words($keyword);
        $core = array_values(array_filter($words, fn (string $word): bool => ! in_array($word, self::LINKING_WORDS, true)));

        return $core === [] ? $words : $core;
    }

    public static function says(string $text, string $keyword): bool
    {
        return self::saysWords(self::words($text), $keyword);
    }

    /**
     * The keywords a text says, in the order given.
     *
     * @param  list<string>  $keywords
     * @return list<string>
     */
    public static function in(string $text, array $keywords): array
    {
        $words = self::words($text);

        return array_values(array_filter($keywords, fn (string $keyword): bool => self::saysWords($words, $keyword)));
    }

    /**
     * @param  list<string>  $words  The text's words (words())
     */
    public static function saysWords(array $words, string $keyword): bool
    {
        $core = self::coreWords($keyword);

        if ($core === [] || $words === []) {
            return false;
        }

        if (count($core) > 1 && self::hasHashtagOf($words, $keyword, $core)) {
            return true;
        }

        $last = count($words) - 1;

        foreach ($words as $start => $word) {
            if ($word !== $core[0]) {
                continue;
            }

            $position = $start;
            $found = true;

            for ($next = 1; $next < count($core); $next++) {
                $position++;
                $skipped = 0;

                while ($position <= $last && $words[$position] !== $core[$next] && in_array($words[$position], self::LINKING_WORDS, true) && $skipped < self::MAX_GAP) {
                    $position++;
                    $skipped++;
                }

                if ($position > $last || $words[$position] !== $core[$next]) {
                    $found = false;

                    break;
                }
            }

            if ($found) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $words
     * @param  list<string>  $core
     */
    private static function hasHashtagOf(array $words, string $keyword, array $core): bool
    {
        $joined = [implode('', $core), implode('', self::words($keyword))];

        foreach ($words as $word) {
            if (in_array($word, $joined, true)) {
                return true;
            }
        }

        return false;
    }
}
