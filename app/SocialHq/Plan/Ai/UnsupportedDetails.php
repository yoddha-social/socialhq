<?php

declare(strict_types=1);

namespace App\SocialHq\Plan\Ai;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * What an AI answer says that its sources don't: names (a capitalised word
 * that doesn't start a sentence), numbers (digits or an English number word)
 * and quotes of four words or more. The writer's prompt forbids inventing
 * these, but a model still does now and then ("our weaver Sita", "three days
 * per tote"), so the reviewer is shown them before approving.
 *
 * A heuristic: it looks for the words, not their meaning, so it errs towards
 * leniency (a number anywhere in the sources, a date's day included, counts
 * as given, and so does numbering such as "Slide 3" or "2."). It only catches
 * capitalised names in scripts that have case, and misses a name that starts
 * a sentence ("Sita wove it"): telling it from any other first word would
 * need a dictionary.
 *
 * Build the source index once with against() and check many texts with it.
 */
final class UnsupportedDetails
{
    public const int MAX = 12;

    /** Capitalised words that are never names to check. */
    private const array COMMON = [
        'i', 'a', 'an', 'the', 'and', 'or', 'but', 'so', 'if', 'our', 'we', 'you', 'your', 'it', 'its', 'this', 'that', 'these', 'those',
        'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
        'january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december',
        'happy', 'new', 'year', 'dm', 'ok', 'okay',
    ];

    /** English number words and the digits they stand for. */
    private const array NUMBER_WORDS = [
        'two' => '2', 'three' => '3', 'four' => '4', 'five' => '5', 'six' => '6', 'seven' => '7', 'eight' => '8', 'nine' => '9', 'ten' => '10',
        'eleven' => '11', 'twelve' => '12', 'fifteen' => '15', 'twenty' => '20', 'thirty' => '30', 'forty' => '40', 'fifty' => '50',
        'hundred' => '100', 'hundreds' => '100', 'thousand' => '1000', 'thousands' => '1000', 'dozen' => '12', 'dozens' => '12',
    ];

    /** Words before a number that make it a position, not a claim ("Slide 3", "Step 2", "#4"). */
    private const string ORDINAL_BEFORE = '/(?:slide|step|part|tip|card|page|frame|beat|scene|shot|no\.?|#)\s*$/iu';

    /**
     * @param  array<string, true>  $words  every word of the sources, lower case
     * @param  array<string, true>  $numbers  every number of the sources, normalised
     */
    private function __construct(
        private readonly array $words,
        private readonly array $numbers,
        private readonly string $flat,
    ) {}

    /**
     * @param  string  $text  what the AI wrote (caption and script, not hashtags)
     * @param  string  $sources  everything the AI was given to write from (see sourceText())
     * @return list<string>
     */
    public static function find(string $text, string $sources): array
    {
        return trim($text) === '' ? [] : self::against($sources)->in($text);
    }

    /**
     * The index of the sources, to check one or many texts against.
     */
    public static function against(string $sources): self
    {
        return new self(self::words($sources), self::numbers($sources), self::flatten($sources));
    }

    /**
     * The sources as plain text: arrays (the writer's or the planner's JSON
     * context) by their values, so an encoded line break ("\n") never glues
     * two words together.
     */
    public static function sourceText(mixed ...$parts): string
    {
        return collect($parts)
            ->flatMap(fn (mixed $part): array => is_array($part) ? Arr::flatten($part) : [$part])
            ->filter(fn (mixed $value): bool => is_scalar($value))
            ->map(fn (mixed $value): string => (string) $value)
            ->implode("\n");
    }

    /**
     * The details of an earlier check that the new text still contains: an
     * edit keeps the warning for what it didn't change, and drops it for what
     * it removed.
     *
     * @param  array<int, string>|null  $details
     * @return list<string>|null
     */
    public static function stillIn(?array $details, string $text): ?array
    {
        if ($details === null || $details === []) {
            return null;
        }

        $flat = self::flatten($text);
        $kept = array_values(array_filter(
            $details,
            fn (string $detail): bool => str_starts_with($detail, '“')
                ? str_contains($flat, self::flatten(trim($detail, '“”')))
                : preg_match('/(?<![\p{L}\p{N}])'.preg_quote($detail, '/').'(?![\p{L}\p{N}])/u', $text) === 1,
        ));

        return $kept === [] ? null : $kept;
    }

    /**
     * @return list<string>
     */
    public function in(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }

        $found = [];

        foreach (self::quotes($text) as $quote) {
            if (! str_contains($this->flat, self::flatten($quote))) {
                $found[] = "“{$quote}”";
            }
        }

        foreach (self::digitRuns($text) as $run) {
            if (! isset($this->numbers[self::normalNumber($run)])) {
                $found[] = $run;
            }
        }

        foreach (self::numberWords($text) as $word) {
            $lower = Str::lower($word);

            if (! isset($this->words[$lower]) && ! isset($this->numbers[self::NUMBER_WORDS[$lower]])) {
                $found[] = $word;
            }
        }

        foreach (self::names($text) as $name) {
            // "Bhai-Tika", "O'Brien": every part has to be in the sources.
            $parts = preg_split('/[\'’-]/u', Str::lower($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if ($parts !== [] && array_any($parts, fn (string $part): bool => ! isset($this->words[$part]))) {
                $found[] = $name;
            }
        }

        return array_slice(array_values(array_unique($found)), 0, self::MAX);
    }

    /**
     * Quoted spans of four words or more. Every quoted span is matched first,
     * so a short one ("Dhaka") never pairs its closing mark with the next
     * quote's opening one.
     *
     * @return list<string>
     */
    private static function quotes(string $text): array
    {
        preg_match_all('/“([^“”\n]{1,300})”|"([^"\n]{1,300})"/u', $text, $matches);

        return array_values(array_filter(
            array_map(fn (string $curly, string $straight): string => trim($curly !== '' ? $curly : $straight), $matches[1], $matches[2]),
            fn (string $quote): bool => count(preg_split('/\s+/u', $quote, -1, PREG_SPLIT_NO_EMPTY) ?: []) >= 4,
        ));
    }

    /**
     * Numbers that state something: not a hashtag's or a handle's digits, and
     * not numbering ("Slide 3", "Step 2", "2." at the start of a line, "1/5").
     *
     * @return list<string>
     */
    private static function digitRuns(string $text): array
    {
        $text = (string) preg_replace('/[#@][\p{L}\p{N}_]+/u', ' ', $text);
        preg_match_all('/\d(?:[\d,.]*\d)?%?/u', $text, $matches, PREG_OFFSET_CAPTURE);
        $runs = [];

        foreach ($matches[0] as [$run, $offset]) {
            $before = self::tail($text, $offset);
            $after = substr($text, $offset + strlen($run), 2);
            $small = strlen($run) <= 2;

            $numbering = $small && (
                preg_match(self::ORDINAL_BEFORE, $before) === 1
                || (preg_match('/(?:^|\n)[ \t]*$/', $before) === 1 && preg_match('/^[.):]/', $after) === 1)
                || preg_match('/^\/\d/', $after) === 1
                || preg_match('/\d\/$/', $before) === 1
            );

            if (! $numbering) {
                $runs[] = $run;
            }
        }

        return $runs;
    }

    /**
     * @return list<string>
     */
    private static function numberWords(string $text): array
    {
        preg_match_all('/\b('.implode('|', array_keys(self::NUMBER_WORDS)).')\b/iu', $text, $matches);

        return $matches[1];
    }

    /**
     * Capitalised words that don't start a sentence, a line or a quote.
     *
     * @return list<string>
     */
    private static function names(string $text): array
    {
        $text = (string) preg_replace('/[#@][\p{L}\p{N}_]+/u', ' ', $text);
        preg_match_all('/(?<![\p{L}\p{N}\'’])\p{Lu}[\p{L}\p{M}]+(?:[\'’-]\p{L}+)*/u', $text, $matches, PREG_OFFSET_CAPTURE);
        $names = [];

        foreach ($matches[0] as [$word, $offset]) {
            if (self::startsSentence($text, $offset)) {
                continue;
            }

            $word = (string) preg_replace('/[\'’]s$/u', '', $word);

            if (mb_strlen($word) > 1 && ! in_array(Str::lower($word), self::COMMON, true)) {
                $names[] = $word;
            }
        }

        return $names;
    }

    /**
     * Up to 48 bytes before a byte offset, cut on a character boundary.
     */
    private static function tail(string $text, int $offset): string
    {
        $start = max(0, $offset - 48);

        // Drop UTF-8 continuation bytes at the start.
        return (string) preg_replace('/^[\x80-\xBF]+/', '', substr($text, $start, $offset - $start));
    }

    /**
     * Whether the word at this byte offset starts the text, a line or a
     * sentence, or follows an emoji, a bullet or an opening quote. Only the
     * last few characters before it are read, so a long script stays cheap.
     */
    private static function startsSentence(string $text, int $offset): bool
    {
        $prefix = self::tail($text, $offset);

        if ($offset === 0 || preg_match('/(?:^|\n)[ \t]*$/', $prefix) === 1 && ($offset <= 48 || str_contains($prefix, "\n"))) {
            return true;
        }

        $before = rtrim($prefix);

        return $before === '' || preg_match('/[.!?…:;"“”(\-–—•*\p{So}\p{Sk}\x{FE0F}\x{200D}]$/u', $before) === 1;
    }

    /**
     * Every word of the sources, lower case, as keys.
     *
     * @return array<string, true>
     */
    private static function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{M}]+/u', Str::lower($text), $matches);

        return array_fill_keys($matches[0], true);
    }

    /**
     * Every number in the sources, without thousands separators or leading
     * zeros, as keys. "2027-01-15" and "07:30" count as their parts too.
     *
     * @return array<string, true>
     */
    private static function numbers(string $text): array
    {
        preg_match_all('/\d(?:[\d,.]*\d)?/u', $text, $matches);
        $numbers = [];

        foreach ($matches[0] as $run) {
            $numbers[self::normalNumber($run)] = true;

            foreach (preg_split('/[^\d]+/', $run, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                $numbers[self::normalNumber($part)] = true;
            }
        }

        return $numbers;
    }

    private static function normalNumber(string $run): string
    {
        $plain = ltrim(str_replace([',', '%'], '', $run), '0');

        return $plain === '' || str_starts_with($plain, '.') ? '0'.$plain : $plain;
    }

    private static function flatten(string $text): string
    {
        return Str::lower(Str::squish((string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text)));
    }
}
