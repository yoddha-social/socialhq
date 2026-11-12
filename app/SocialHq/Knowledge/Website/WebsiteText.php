<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Website;

/**
 * Turns the pages of a run into what is kept or sent: lines already seen
 * on an earlier page (a menu or footer that survived cleaning, a repeated
 * call to action) are dropped, pages left with no text or the same text
 * as another go, and each page and the whole are cut to a size.
 */
final class WebsiteText
{
    /** A page with fewer letters than this after dedupe says nothing new. */
    private const MIN_LETTERS = 40;

    /**
     * @param  list<array{url: string, title: string, text: string}>  $pages  In reading order (home page first)
     * @return list<array{url: string, title: string, text: string}>
     */
    public static function deduplicate(array $pages): array
    {
        $seenLines = [];
        $seenPages = [];
        $kept = [];

        foreach ($pages as $page) {
            $lines = [];

            foreach (preg_split('/\n+/', $page['text']) ?: [] as $line) {
                $line = trim($line);
                $key = mb_strtolower((string) preg_replace('/[\s\p{P}]+/u', ' ', $line));

                if ($key === '' || trim($key) === '' || isset($seenLines[$key])) {
                    continue;
                }

                $seenLines[$key] = true;
                $lines[] = $line;
            }

            $text = implode("\n", $lines);
            $hash = hash('sha256', $text);

            if (preg_match_all('/[\p{L}\p{N}]/u', $text) < self::MIN_LETTERS || isset($seenPages[$hash])) {
                continue;
            }

            $seenPages[$hash] = true;
            $kept[] = [...$page, 'text' => $text];
        }

        return $kept;
    }

    /**
     * Each page cut to $perPage characters and the whole to $total, in order.
     *
     * @param  list<array{url: string, title: string, text: string}>  $pages
     * @return list<array{url: string, title: string, text: string}>
     */
    public static function cap(array $pages, int $perPage, int $total): array
    {
        $left = $total;
        $capped = [];

        foreach ($pages as $page) {
            $room = min($perPage, $left - mb_strlen($page['url']) - mb_strlen($page['title']));

            if ($room < 200) {
                break;
            }

            $text = mb_strlen($page['text']) > $room ? rtrim(mb_substr($page['text'], 0, $room - 1)).'…' : $page['text'];
            $left -= mb_strlen($text) + mb_strlen($page['url']) + mb_strlen($page['title']);
            $capped[] = [...$page, 'text' => $text];
        }

        return $capped;
    }

    /**
     * The pages as one document for search: each under its title and address.
     *
     * @param  list<array{url: string, title: string, text: string}>  $pages
     */
    public static function document(array $pages): string
    {
        return implode("\n\n", array_map(
            fn (array $page): string => trim(($page['title'] !== '' ? "{$page['title']}\n" : '')."{$page['url']}\n\n{$page['text']}"),
            $pages,
        ));
    }
}
