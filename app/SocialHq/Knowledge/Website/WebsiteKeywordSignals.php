<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Website;

use App\SocialHq\Knowledge\Keywords\KeywordMatcher;
use Illuminate\Support\Str;

/**
 * What a website stresses, read in code from the pages a run already read
 * (no more crawling), for the keyword step: each page's title and address,
 * its headings (short lines), its questions (lines ending in "?") and its
 * opening words, and the phrases of two or three words that several pages
 * repeat. It also says which pages say a phrase, for a suggestion's "why".
 */
final class WebsiteKeywordSignals
{
    /** Headings and questions kept per page. */
    private const int HEADINGS = 10;

    private const int QUESTIONS = 5;

    /** Characters of each page's opening text. */
    private const int OPENING = 400;

    /** Repeated phrases kept for the AI. */
    private const int PHRASES = 30;

    /** Words a phrase may not start or end with. */
    private const array STOPWORDS = [
        'a', 'about', 'all', 'also', 'an', 'and', 'any', 'are', 'as', 'at', 'be', 'by', 'can', 'do', 'for', 'from',
        'get', 'has', 'have', 'how', 'i', 'if', 'in', 'into', 'is', 'it', 'its', 'more', 'my', 'no', 'not', 'of', 'on',
        'or', 'our', 'out', 'so', 'than', 'that', 'the', 'their', 'them', 'then', 'there', 'these', 'they', 'this', 'to',
        'up', 'us', 'was', 'we', 'what', 'when', 'which', 'who', 'will', 'with', 'you', 'your',
    ];

    /**
     * @param  list<array{url: string, title: string, text: string}>  $pages  The run's pages (WebsiteText::deduplicate())
     * @param  list<string>  $brandNames  The brand's name and its site's name: never a phrase of their words
     * @return array{pages: list<array{title: string, url: string, headings: list<string>, questions: list<string>, opening: string}>, repeated: list<array{phrase: string, pages: int}>}
     */
    public static function of(array $pages, array $brandNames): array
    {
        $summaries = [];
        $phrasePages = [];
        $brandWords = array_fill_keys(array_merge(...array_map(KeywordMatcher::words(...), $brandNames ?: [''])), true);

        foreach ($pages as $index => $page) {
            $headings = [];
            $questions = [];

            foreach (preg_split('/\n+/', $page['text']) ?: [] as $line) {
                $line = Str::squish($line);
                $words = KeywordMatcher::words($line);

                if ($words === []) {
                    continue;
                }

                if (str_ends_with($line, '?') && mb_strlen($line) <= 160 && count($questions) < self::QUESTIONS) {
                    $questions[] = $line;
                } elseif (count($words) <= 8 && mb_strlen($line) <= 70 && ! preg_match('/[.!:;,]$/u', $line) && count($headings) < self::HEADINGS) {
                    $headings[] = $line;
                }
            }

            $summaries[] = [
                'title' => $page['title'],
                'url' => $page['url'],
                'headings' => $headings,
                'questions' => $questions,
                'opening' => Str::limit(Str::squish($page['text']), self::OPENING),
            ];

            foreach (array_keys(self::phrasesOf("{$page['title']}\n{$page['text']}", $brandWords)) as $phrase) {
                $phrasePages[$phrase][$index] = true;
            }
        }

        $least = count($pages) > 6 ? 3 : 2;
        $repeated = [];

        foreach ($phrasePages as $phrase => $on) {
            if (count($on) >= $least) {
                $repeated[] = ['phrase' => (string) $phrase, 'pages' => count($on)];
            }
        }

        usort($repeated, fn (array $left, array $right): int => [$right['pages'], substr_count($right['phrase'], ' ')] <=> [$left['pages'], substr_count($left['phrase'], ' ')]);

        // A shorter phrase every page of a longer one already says adds nothing.
        $kept = [];

        foreach ($repeated as $candidate) {
            $covered = collect($kept)->contains(fn (array $longer): bool => $longer['pages'] === $candidate['pages'] && str_contains(" {$longer['phrase']} ", " {$candidate['phrase']} "));

            if (! $covered) {
                $kept[] = $candidate;
            }

            if (count($kept) >= self::PHRASES) {
                break;
            }
        }

        return ['pages' => $summaries, 'repeated' => $kept];
    }

    /**
     * The pages that say a phrase (KeywordMatcher, in the title or the text):
     * how many, and the first titles.
     *
     * @param  list<array{url: string, title: string, text: string}>  $pages
     * @return array{pages: int, titles: list<string>}
     */
    public static function pagesSaying(array $pages, string $phrase, int $titles = 3): array
    {
        $saying = array_values(array_filter($pages, fn (array $page): bool => KeywordMatcher::says("{$page['title']}\n{$page['text']}", $phrase)));

        return [
            'pages' => count($saying),
            'titles' => array_slice(array_values(array_unique(array_filter(array_map(fn (array $page): string => Str::limit(trim($page['title']), 60), $saying)))), 0, $titles),
        ];
    }

    /**
     * The phrases of two or three words in a text, none starting or ending
     * with a small word, none with a word of the brand's name.
     *
     * @param  array<string, true>  $brandWords
     * @return array<string, true>
     */
    private static function phrasesOf(string $text, array $brandWords): array
    {
        $phrases = [];

        foreach (preg_split('/\n+/', $text) ?: [] as $line) {
            $words = KeywordMatcher::words($line);
            $count = count($words);

            for ($start = 0; $start < $count; $start++) {
                foreach ([2, 3] as $length) {
                    $slice = array_slice($words, $start, $length);

                    if (count($slice) !== $length
                        || in_array($slice[0], self::STOPWORDS, true)
                        || in_array($slice[$length - 1], self::STOPWORDS, true)
                        || array_intersect_key(array_flip($slice), $brandWords) !== []
                        || collect($slice)->contains(fn (string $word): bool => is_numeric($word) || mb_strlen($word) < 2)) {
                        continue;
                    }

                    $phrases[implode(' ', $slice)] = true;
                }
            }
        }

        return $phrases;
    }
}
