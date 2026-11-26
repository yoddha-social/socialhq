<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Website;

use App\Models\Workspace;
use App\SocialHq\Knowledge\BrandProfile;
use App\SocialHq\Knowledge\Keywords\BrandKeywords;
use App\SocialHq\Knowledge\Keywords\KeywordCheck;
use App\SocialHq\Knowledge\Keywords\KeywordMatcher;
use App\SocialHq\Knowledge\Keywords\KeywordTagKind;
use App\SocialHq\Knowledge\Keywords\Occasion;
use App\SocialHq\Knowledge\KnowledgeEntry;
use App\SocialHq\Knowledge\KnowledgeKind;
use App\SocialHq\Plan\PlanOptions;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Uri;

/**
 * Turns WebsiteKeywordReader's answer into keyword suggestions, checked in
 * code first. Left out: anything but 1 to 4 words, the brand's own name,
 * a claim word (`claim_words`), a price, a keyword the catalogue already
 * has (KeywordMatcher: the same words, linking words aside) and one no page
 * of the run says (neither it nor the words it came from). Up to 5 main
 * keywords while main slots are free (the rest are offered for the
 * library, never in place of a main one) and 15 more while the library has
 * room. Tags are kept only when they name the brand's own pillar, product
 * or service, or occasion. Each one is checked like any keyword
 * (KeywordCheck::preview(), warnings only), and carries why it was
 * suggested (the pages that say it) and a caption line that says it.
 *
 * A suggestion: key, type "keyword", text, main, main_full (asked for as
 * main while main was full), group, tags, pages and page_titles, sample,
 * verdict, reason and status (open until an admin decides).
 */
final class WebsiteKeywordSuggestions
{
    public const int MAX_MAIN = 5;

    public const int MAX_OTHERS = 15;

    /**
     * Main slots still free (at most MAX_MAIN): what the keyword step may suggest as main.
     */
    public static function mainSlots(Workspace $workspace): int
    {
        $main = count(array_filter(BrandKeywords::forWorkspace($workspace), fn (array $keyword): bool => $keyword['main']));

        return max(0, min(self::MAX_MAIN, BrandKeywords::maxMain() - $main));
    }

    /**
     * What the keyword step is told the team has.
     *
     * @return array{keywords: list<string>, pillars: list<string>, products: list<string>, occasions: list<array{key: string, name: string}>}
     */
    public static function teamHas(Workspace $workspace): array
    {
        return [
            'keywords' => array_column(BrandKeywords::forWorkspace($workspace), 'text'),
            'pillars' => PlanOptions::pillars(BrandProfile::query()->where('workspace_id', $workspace->id)->first()),
            'products' => self::products($workspace)->pluck('title')->take(60)->values()->all(),
            'occasions' => array_map(fn (array $occasion): array => ['key' => $occasion['key'], 'name' => $occasion['name']], array_slice(Occasion::choices($workspace), 0, 80)),
        ];
    }

    /**
     * The names the brand goes by: the workspace's and its site's.
     *
     * @return list<string>
     */
    public static function brandNames(Workspace $workspace, string $url): array
    {
        $host = (string) preg_replace('/^www\./', '', strtolower((string) Uri::of($url)->host()));

        return array_values(array_filter([(string) $workspace->name, Str::before($host, '.')]));
    }

    /**
     * @param  list<array{url: string, title: string, text: string}>  $pages  The run's pages
     * @return list<array<string, mixed>>
     */
    public static function build(mixed $response, Workspace $workspace, array $pages, string $url): array
    {
        $existing = BrandKeywords::forWorkspace($workspace);
        $existingTexts = array_column($existing, 'text');
        $mainFree = self::mainSlots($workspace);
        $othersFree = min(self::MAX_OTHERS, max(0, BrandKeywords::maxSupporting() - count(array_filter($existing, fn (array $keyword): bool => ! $keyword['main']))));
        $brandNames = self::brandNames($workspace, $url);
        $pillars = PlanOptions::pillars(BrandProfile::query()->where('workspace_id', $workspace->id)->first());
        $products = self::products($workspace);
        $occasions = collect(Occasion::choices($workspace));
        $claims = array_values(array_filter(array_map(fn (mixed $word): string => mb_strtolower(trim((string) $word)), (array) config('socialhq.knowledge.keywords.claim_words', []))));
        $seen = [];
        $mains = 0;
        $others = 0;
        $suggestions = [];

        foreach ((array) data_get($response, 'keywords', []) as $row) {
            $text = BrandKeywords::clean((string) data_get($row, 'text'));
            $words = KeywordMatcher::words($text);
            $key = BrandKeywords::sameKey($text);

            if (count($words) < 1 || count($words) > 4 || mb_strlen($text) < BrandKeywords::MIN_LENGTH || mb_strlen($text) > BrandKeywords::MAX_LENGTH
                || isset($seen[$key]) || self::inCatalogue($text, $existingTexts) || self::namesBrand($text, $brandNames)
                || self::isClaim($text, $claims) || self::isPrice($text)) {
                continue;
            }

            // Only what the site says: the keyword itself, or the words it was rewritten from.
            $found = WebsiteKeywordSignals::pagesSaying($pages, $text);
            $source = Str::squish((string) data_get($row, 'source'));

            if ($found['pages'] === 0 && $source !== '') {
                $found = WebsiteKeywordSignals::pagesSaying($pages, $source);
            }

            if ($found['pages'] === 0) {
                continue;
            }

            $wantsMain = data_get($row, 'main') === true;
            $main = $wantsMain && $mains < $mainFree;

            if (! $main && $others >= $othersFree) {
                continue;
            }

            $main ? $mains++ : $others++;
            $seen[$key] = true;
            $sample = Str::limit(Str::squish((string) data_get($row, 'sample')), 220);
            $group = (string) data_get($row, 'group');

            $suggestions[] = [
                'type' => 'keyword',
                'text' => $text,
                'main' => $main,
                'main_full' => $wantsMain && ! $main,
                'group' => in_array($group, WebsiteKeywordReader::GROUPS, true) ? $group : 'features',
                'tags' => self::tags($row, $pillars, $products, $occasions),
                'pages' => $found['pages'],
                'page_titles' => $found['titles'],
                // A caption line that doesn't say the keyword shows how not to use it: left out.
                'sample' => KeywordMatcher::says($sample, $text) ? $sample : '',
            ];
        }

        $checks = collect(KeywordCheck::preview($workspace, array_column($suggestions, 'text')))->keyBy('text');

        return array_values(array_map(fn (array $suggestion, int $index): array => [
            'key' => 'keyword-'.($index + 1),
            ...$suggestion,
            'verdict' => (string) data_get($checks->get($suggestion['text']), 'verdict', 'good'),
            'reason' => (string) data_get($checks->get($suggestion['text']), 'reason', ''),
            'status' => WebsiteSuggestions::OPEN,
        ], $suggestions, array_keys($suggestions)));
    }

    /**
     * The tags the answer names, when they are the brand's own: a pillar, a
     * product or service, an occasion (by key or name), a range of months.
     *
     * @param  list<string>  $pillars
     * @param  Collection<int, KnowledgeEntry>  $products
     * @param  Collection<int, array{key: string, name: string, starts_on: string}>  $occasions
     * @return list<array{kind: string, value: string|null, entry_id: int|null, from: int|null, to: int|null, label: string}>
     */
    private static function tags(mixed $row, array $pillars, Collection $products, Collection $occasions): array
    {
        $tags = [];
        $pillar = BrandKeywords::sameKey((string) data_get($row, 'pillar'));
        $product = BrandKeywords::sameKey((string) data_get($row, 'product'));
        $occasion = trim((string) data_get($row, 'occasion'));
        $tag = fn (KeywordTagKind $kind, array $fields, string $label): array => ['kind' => $kind->value, 'value' => null, 'entry_id' => null, 'from' => null, 'to' => null, ...$fields, 'label' => $label];

        if ($pillar !== '' && ($name = collect($pillars)->first(fn (string $candidate): bool => BrandKeywords::sameKey($candidate) === $pillar)) !== null) {
            $tags[] = $tag(KeywordTagKind::Pillar, ['value' => $name], $name);
        }

        if ($product !== '' && ($entry = $products->first(fn (KnowledgeEntry $candidate): bool => BrandKeywords::sameKey($candidate->title) === $product)) !== null) {
            $tags[] = $tag(KeywordTagKind::Product, ['entry_id' => $entry->id], $entry->title);
        }

        $named = $occasion === '' ? null : $occasions->first(fn (array $candidate): bool => $candidate['key'] === Occasion::key(Str::slug($occasion)) || BrandKeywords::sameKey($candidate['name']) === BrandKeywords::sameKey($occasion));

        if ($named !== null) {
            $tags[] = $tag(KeywordTagKind::Event, ['value' => $named['key']], $named['name']);
        }

        $from = (int) data_get($row, 'months_from');
        $to = (int) data_get($row, 'months_to');

        if ($from >= 1 && $from <= 12 && $to >= 1 && $to <= 12) {
            $tags[] = $tag(KeywordTagKind::Months, ['from' => $from, 'to' => $to], Occasion::monthsLabel($from, $to));
        }

        return $tags;
    }

    /**
     * @return Collection<int, KnowledgeEntry>
     */
    private static function products(Workspace $workspace): Collection
    {
        return KnowledgeEntry::query()
            ->where('workspace_id', $workspace->id)
            ->where('kind', KnowledgeKind::Product)
            ->orderBy('title')
            ->get(['id', 'title']);
    }

    /**
     * Whether the catalogue has it: the same words, linking words aside.
     *
     * @param  list<string>  $existing
     */
    private static function inCatalogue(string $text, array $existing): bool
    {
        foreach ($existing as $keyword) {
            if (BrandKeywords::sameKey($keyword) === BrandKeywords::sameKey($text)
                || (KeywordMatcher::says($text, $keyword) && KeywordMatcher::says($keyword, $text))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether it says one of the brand's names, or a name's words run together.
     *
     * @param  list<string>  $names
     */
    private static function namesBrand(string $text, array $names): bool
    {
        $words = KeywordMatcher::words($text);

        foreach ($names as $name) {
            $nameWords = KeywordMatcher::words($name);

            if ($nameWords !== [] && (KeywordMatcher::says($text, $name) || in_array(implode('', $nameWords), $words, true))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $claims
     */
    private static function isClaim(string $text, array $claims): bool
    {
        $words = KeywordMatcher::words($text);

        foreach ($claims as $claim) {
            $claimWords = KeywordMatcher::words($claim);

            if (($claimWords === [] && str_contains($text, $claim)) || ($claimWords !== [] && KeywordMatcher::says($text, $claim)) || in_array($claim, $words, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A price: an amount with a currency, or a "/-" amount.
     */
    private static function isPrice(string $text): bool
    {
        return preg_match('/(\brs\.?|\bnpr\b|\busd\b|\binr\b|\$|€|£|₹|रु)\s*\d|\d[\d,.]*\s*(\brs\b|\bnpr\b|\busd\b|\binr\b|\/-|\$|€|£|₹)/iu', $text) === 1;
    }
}
