<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Documents;

/**
 * Splits a document's text into excerpts of about $size characters for
 * search and prompts: whole paragraphs where they fit, else sentences
 * (the Devanagari danda ends one too), else words. Each excerpt after the
 * first starts with the last sentence of the one before when it is short,
 * so a fact cut at a boundary is still found with its context.
 */
final class Chunker
{
    /**
     * @return list<string>
     */
    public static function split(string $text, ?int $size = null, ?int $overlap = null, ?int $max = null): array
    {
        $size ??= max(200, (int) config('socialhq.knowledge.documents.chunk_characters', 900));
        $overlap ??= intdiv($size, 6);
        $max ??= max(1, (int) config('socialhq.knowledge.documents.max_chunks', 400));

        $chunks = [];
        $current = [];
        $length = 0;

        foreach (self::units($text, $size) as [$unit, $startsParagraph]) {
            $unitLength = mb_strlen($unit) + 2;

            if ($current !== [] && $length + $unitLength > $size) {
                $chunks[] = self::join($current);

                if (count($chunks) >= $max) {
                    return $chunks;
                }

                $last = end($current);
                $current = mb_strlen($last[0]) <= $overlap && ! $startsParagraph ? [$last] : [];
                $length = $current === [] ? 0 : mb_strlen($last[0]) + 2;
            }

            $current[] = [$unit, $startsParagraph];
            $length += $unitLength;
        }

        if ($current !== []) {
            $chunks[] = self::join($current);
        }

        return array_slice($chunks, 0, $max);
    }

    /**
     * Paragraphs, or the sentences (or word runs) of a paragraph longer than
     * $size, each with whether it starts a paragraph.
     *
     * @return list<array{0: string, 1: bool}>
     */
    private static function units(string $text, int $size): array
    {
        $units = [];

        foreach (preg_split('/\n{2,}/', $text) ?: [] as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            if (mb_strlen($paragraph) <= $size) {
                $units[] = [$paragraph, true];

                continue;
            }

            $first = true;

            foreach (preg_split('/(?<=[.!?।॥])\s+/u', $paragraph) ?: [] as $sentence) {
                foreach (self::words($sentence, $size) as $piece) {
                    $units[] = [$piece, $first];
                    $first = false;
                }
            }
        }

        return $units;
    }

    /**
     * A sentence longer than $size, cut at spaces (or hard, with none).
     *
     * @return list<string>
     */
    private static function words(string $sentence, int $size): array
    {
        $pieces = [];

        while (mb_strlen($sentence) > $size) {
            $head = mb_substr($sentence, 0, $size);
            $space = mb_strrpos($head, ' ');
            $cut = $space !== false && $space > intdiv($size, 2) ? $space : $size;
            $pieces[] = trim(mb_substr($sentence, 0, $cut));
            $sentence = trim(mb_substr($sentence, $cut));
        }

        if ($sentence !== '') {
            $pieces[] = $sentence;
        }

        return $pieces;
    }

    /**
     * @param  list<array{0: string, 1: bool}>  $units
     */
    private static function join(array $units): string
    {
        $text = '';

        foreach ($units as $index => [$unit, $startsParagraph]) {
            $text .= $index === 0 ? $unit : ($startsParagraph ? "\n\n" : ' ').$unit;
        }

        return $text;
    }
}
