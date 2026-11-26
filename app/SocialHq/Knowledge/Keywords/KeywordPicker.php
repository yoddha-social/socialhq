<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Keywords;

use App\Models\Workspace;
use App\SocialHq\Knowledge\Search\Tokens;
use App\SocialHq\Plan\PlanItem;
use Carbon\CarbonImmutable;

/**
 * The keywords one post gets, picked by the app, never by the AI (owner
 * decision 2026-11-26): only these go into its prompt.
 *
 * 1. One main keyword: the main one whose tags fit the post best, then the
 *    one sharing most words with what the post is about, then the next in
 *    turn (PostContext::$seed), so posts don't all get the same one.
 * 2. Up to `per_post.library` library keywords whose tags fit the post: its
 *    pillar, a product or service it is about (every word of the entry's
 *    title in what it is about, one aside when the title is longer than two
 *    words), an event in season on its day (Occasion) or a range of months
 *    holding it. An occasion counts most, then pillar and product, then the
 *    words shared with the topic; ties take turns. A keyword whose tags
 *    don't fit is left out.
 * 3. When fewer than `per_post.min_library` fit, general keywords (no tags)
 *    fill in, those that share words with the topic first, then in turn.
 *
 * A person may change the picks for a post (chosen()).
 */
final class KeywordPicker
{
    /** How much each kind of tag that fits counts. */
    private const array WEIGHTS = ['event' => 4, 'months' => 4, 'pillar' => 3, 'product' => 3];

    public static function libraryPerPost(): int
    {
        return max(0, (int) config('socialhq.knowledge.keywords.per_post.library', 3));
    }

    public static function minLibraryPerPost(): int
    {
        return min(self::libraryPerPost(), max(0, (int) config('socialhq.knowledge.keywords.per_post.min_library', 2)));
    }

    /**
     * The most keywords a person may choose for one post.
     */
    public static function maxPerPost(): int
    {
        return max(1, (int) config('socialhq.knowledge.keywords.per_post.max', 4));
    }

    /**
     * The app's pick for a post: the main keyword first, then the library
     * ones. `matched` lists the tags that fit (empty: general, or main).
     *
     * @param  list<array<string, mixed>>|null  $catalogue  BrandKeywords::catalogue(), when the caller has it
     * @return list<array{text: string, main: bool, tags: list<array{kind: string, label: string}>, matched: list<array{kind: string, label: string}>}>
     */
    public static function pick(Workspace $workspace, PostContext $context, ?array $catalogue = null): array
    {
        $catalogue ??= BrandKeywords::catalogue($workspace);

        if ($catalogue === []) {
            return [];
        }

        $day = $context->day ?? CarbonImmutable::today();
        $hasEvents = collect($catalogue)->contains(fn (array $keyword): bool => collect($keyword['tags'])->contains('kind', KeywordTagKind::Event->value));
        $active = $hasEvents ? Occasion::activeOn($workspace, $day) : [];

        if ($context->occasion !== null) {
            $active[$context->occasion] = $context->occasion;
        }

        $focus = array_fill_keys(Tokens::of($context->focus), true);
        $pillar = $context->pillar === null ? null : BrandKeywords::sameKey($context->pillar);
        $scored = [];

        foreach ($catalogue as $index => $keyword) {
            $matched = array_values(array_filter($keyword['tags'], fn (array $tag): bool => match ($tag['kind']) {
                KeywordTagKind::Pillar->value => $pillar !== null && BrandKeywords::sameKey((string) $tag['value']) === $pillar,
                KeywordTagKind::Product->value => self::isAbout($focus, $tag['label']),
                KeywordTagKind::Event->value => isset($active[(string) $tag['value']]),
                KeywordTagKind::Months->value => Occasion::monthsHold((int) $tag['from'], (int) $tag['to'], $day->month),
                default => false,
            }));

            $scored[] = [
                ...$keyword,
                'index' => $index,
                'fit' => array_sum(array_map(fn (array $tag): int => self::WEIGHTS[$tag['kind']] ?? 0, $matched)),
                'topic' => count(array_intersect_key(array_fill_keys(Tokens::of($keyword['text']), true), $focus)),
                'matched' => $matched,
            ];
        }

        $main = array_values(array_filter($scored, fn (array $keyword): bool => $keyword['main']));
        $library = array_values(array_filter($scored, fn (array $keyword): bool => ! $keyword['main']));

        $picked = array_slice(self::ranked($main, $context->seed), 0, 1);
        $fitting = self::ranked(array_values(array_filter($library, fn (array $keyword): bool => $keyword['fit'] > 0)), $context->seed);
        $chosen = array_slice($fitting, 0, self::libraryPerPost());

        if (count($chosen) < self::minLibraryPerPost()) {
            $general = self::ranked(array_values(array_filter($library, fn (array $keyword): bool => $keyword['tags'] === [])), $context->seed * max(1, self::minLibraryPerPost()));
            $chosen = [...$chosen, ...array_slice($general, 0, self::minLibraryPerPost() - count($chosen))];
        }

        return array_map(self::present(...), [...$picked, ...$chosen]);
    }

    /**
     * The keywords a person chose for a post, as the catalogue has them, in
     * their order: unknown ones are dropped, maxPerPost() at most.
     *
     * @param  array<mixed>  $texts
     * @param  list<array<string, mixed>>|null  $catalogue  BrandKeywords::catalogue(), when the caller has it
     * @return list<array{text: string, main: bool, tags: list<array{kind: string, label: string}>, matched: list<array{kind: string, label: string}>}>
     */
    public static function chosen(Workspace $workspace, array $texts, ?array $catalogue = null): array
    {
        $catalogue = collect($catalogue ?? BrandKeywords::catalogue($workspace))->keyBy(fn (array $keyword): string => BrandKeywords::sameKey($keyword['text']));
        $chosen = [];

        foreach ($texts as $text) {
            $keyword = $catalogue->get(BrandKeywords::sameKey((string) (is_string($text) ? $text : '')));

            if ($keyword !== null && ! isset($chosen[$keyword['text']])) {
                $chosen[$keyword['text']] = self::present([...$keyword, 'matched' => []]);
            }
        }

        return array_slice(array_values($chosen), 0, self::maxPerPost());
    }

    /**
     * What an item's copy is written with: the keywords a person chose for
     * it, else the app's pick.
     *
     * @return list<array{text: string, main: bool, tags: list<array{kind: string, label: string}>, matched: list<array{kind: string, label: string}>}>
     */
    public static function forItem(PlanItem $item): array
    {
        return is_array($item->seo_keywords)
            ? self::chosen($item->workspace, $item->seo_keywords)
            : self::pick($item->workspace, PostContext::forItem($item));
    }

    /**
     * "Keywords for this post" on an item or in the composer: what goes to
     * the AI (`picked`: the person's choice, else the app's pick), the app's
     * pick to go back to, whether a person chose, and the catalogue to
     * choose from. Null when the brand has no keywords.
     *
     * @param  array<mixed>|null  $chosen  The keywords a person chose, or null
     * @return array{picked: list<array<string, mixed>>, app_pick: list<array<string, mixed>>, chosen: bool, catalogue: list<array{text: string, main: bool, tags: list<array{kind: string, label: string}>}>, max: int}|null
     */
    public static function panel(Workspace $workspace, PostContext $context, ?array $chosen = null): ?array
    {
        $catalogue = BrandKeywords::catalogue($workspace);

        if ($catalogue === []) {
            return null;
        }

        $appPick = self::pick($workspace, $context, $catalogue);

        return [
            'picked' => $chosen === null ? $appPick : self::chosen($workspace, $chosen, $catalogue),
            'app_pick' => $appPick,
            'chosen' => $chosen !== null,
            'catalogue' => array_map(fn (array $keyword): array => [
                'text' => $keyword['text'],
                'main' => $keyword['main'],
                'tags' => array_map(fn (array $tag): array => ['kind' => $tag['kind'], 'label' => $tag['label']], $keyword['tags']),
            ], $catalogue),
            'max' => self::maxPerPost(),
        ];
    }

    /**
     * Best fit first, then most words shared with the topic, then in turn:
     * the seed decides which keyword leads, so ties rotate from post to post.
     *
     * @param  list<array<string, mixed>>  $keywords
     * @return list<array<string, mixed>>
     */
    private static function ranked(array $keywords, int $seed): array
    {
        $count = count($keywords);

        // Each keyword's turn: its place in the admin's order, counted from the one the seed points at.
        foreach ($keywords as $position => $keyword) {
            $keywords[$position]['turn'] = (($position - $seed) % max(1, $count) + $count) % max(1, $count);
        }

        usort($keywords, fn (array $left, array $right): int => [$right['fit'], $right['topic'], $left['turn']] <=> [$left['fit'], $left['topic'], $right['turn']]);

        return $keywords;
    }

    /**
     * Whether a post is about a product: every word of its title in what
     * the post is about, one aside when the title is longer than two words.
     *
     * @param  array<string, true>  $focus
     */
    private static function isAbout(array $focus, string $title): bool
    {
        $words = array_values(array_unique(Tokens::of($title)));

        if ($words === [] || $focus === []) {
            return false;
        }

        $found = count(array_filter($words, fn (string $word): bool => isset($focus[$word])));

        return $found >= (count($words) > 2 ? count($words) - 1 : count($words));
    }

    /**
     * @param  array<string, mixed>  $keyword
     * @return array{text: string, main: bool, tags: list<array{kind: string, label: string}>, matched: list<array{kind: string, label: string}>}
     */
    private static function present(array $keyword): array
    {
        $short = fn (array $tag): array => ['kind' => $tag['kind'], 'label' => $tag['label']];

        return [
            'text' => $keyword['text'],
            'main' => $keyword['main'],
            'tags' => array_map($short, $keyword['tags']),
            'matched' => array_map($short, $keyword['matched']),
        ];
    }
}
