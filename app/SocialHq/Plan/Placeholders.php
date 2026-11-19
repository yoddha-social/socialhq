<?php

declare(strict_types=1);

namespace App\SocialHq\Plan;

use Illuminate\Support\Str;

/**
 * Square-bracket gaps left in copy for someone to fill ("[customer's city]",
 * "[link]"). Copy with one can't be approved and can't become a post: it
 * would go out with the brackets in it. Only what goes out counts (the
 * caption and the hashtags): a video script marks shots in brackets
 * ("[close-up of the loom]"). A markdown link counts too: networks post
 * "[text](url)" as typed, brackets included.
 */
final class Placeholders
{
    private const string PATTERN = '/\[[^\[\]\n]{1,80}\]/u';

    /**
     * The gaps in the given texts, each once, in order.
     *
     * @return list<string>
     */
    public static function in(?string ...$texts): array
    {
        $found = [];

        foreach ($texts as $text) {
            if ($text === null || $text === '') {
                continue;
            }

            preg_match_all(self::PATTERN, $text, $matches);

            foreach ($matches[0] as $match) {
                $found[Str::lower($match)] ??= $match;
            }
        }

        return array_values($found);
    }

    /**
     * The gaps in what a version would post: its caption and hashtags.
     *
     * @return list<string>
     */
    public static function inVersion(?ItemVersion $version): array
    {
        return $version === null ? [] : self::in($version->caption, $version->hashtags);
    }
}
