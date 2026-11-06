<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Search;

/**
 * Words for search: runs of Unicode letters, combining marks and digits (so
 * Devanagari words keep their vowel signs), lowercased, without common
 * English words, with a plural "s" dropped ("prices" finds "price").
 */
final class Tokens
{
    private const STOPWORDS = [
        'a', 'about', 'after', 'all', 'also', 'an', 'and', 'any', 'are', 'as', 'at', 'be', 'been', 'but', 'by',
        'can', 'do', 'does', 'for', 'from', 'get', 'had', 'has', 'have', 'he', 'her', 'his', 'how', 'i', 'if',
        'in', 'into', 'is', 'it', 'its', 'just', 'me', 'more', 'my', 'no', 'not', 'of', 'on', 'or', 'our',
        'out', 'post', 'posts', 'she', 'so', 'than', 'that', 'the', 'their', 'them', 'then', 'there', 'these',
        'they', 'this', 'to', 'up', 'us', 'was', 'we', 'were', 'what', 'when', 'which', 'who', 'will', 'with',
        'would', 'you', 'your',
    ];

    /**
     * @return list<string>
     */
    public static function of(string $text): array
    {
        preg_match_all('/[\p{L}\p{M}\p{N}]+/u', mb_strtolower($text), $matches);

        $tokens = [];

        foreach ($matches[0] as $word) {
            if (isset(self::stopwords()[$word]) || (mb_strlen($word) < 2 && ! is_numeric($word))) {
                continue;
            }

            $tokens[] = self::stem($word);
        }

        return $tokens;
    }

    private static function stem(string $word): string
    {
        if (strlen($word) > 3 && str_ends_with($word, 's') && ! str_ends_with($word, 'ss') && ! str_ends_with($word, 'us') && ! str_ends_with($word, 'is')) {
            return substr($word, 0, -1);
        }

        return $word;
    }

    /**
     * @return array<string, true>
     */
    private static function stopwords(): array
    {
        static $set = null;

        return $set ??= array_fill_keys(self::STOPWORDS, true);
    }
}
