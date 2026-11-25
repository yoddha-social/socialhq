<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Keywords;

use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\Workspace;
use App\SocialHq\Knowledge\Audience\Audience;
use App\SocialHq\Knowledge\Audience\AudienceCountries;
use App\SocialHq\Knowledge\BrandProfile;
use App\SocialHq\Knowledge\Documents\KnowledgeChunk;
use App\SocialHq\Knowledge\Documents\KnowledgeDocument;
use App\SocialHq\Knowledge\KnowledgeEntry;
use App\SocialHq\Knowledge\Search\Tokens;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The keyword check: each SEO keyword against what Social HQ knows of the
 * brand, in code, no AI. It can't see search demand; it says whether a
 * keyword fits the brand. First match wins:
 *
 * 1. Conflict: a word or phrase of "Words and phrases to avoid" is in it.
 * 2. Weak: no single place (a profile field, an entry used in AI, an excerpt
 *    of a document used in AI) holds every word of it, linking and claim
 *    words aside. Words are compared as search compares them (Tokens).
 * 3. Check: one word (too broad), a claim word, a place we know (a country,
 *    a time zone city or a LocalPlaces town) that isn't an audience place or
 *    part of one, or the same words as another keyword.
 * 4. Good fit.
 *
 * Every verdict is a warning; every keyword still goes to the AI. Reasons
 * name what the check found (the entries and documents, the missing words,
 * the place); a fix comes in two forms, one for whoever may change the
 * keywords and one that says who can (member_fixes).
 */
final class KeywordCheck
{
    /** At most this many places listed under "Found in your brand knowledge". */
    private const int FOUND_LIMIT = 4;

    /** Profile fields that say what the brand is (not the words to avoid). */
    private const array PROFILE_FIELDS = ['about', 'audience', 'voice', 'words_to_use'];

    /** At most this many recent posts are read. */
    private const int POSTS_LIMIT = 1000;

    /** Part of the cache key: raise it when the rules change, so cached verdicts are read again. */
    private const int RULES_VERSION = 4;

    /**
     * @param  list<array{kind: 'profile'|'entry'|'document', id: int|string, document_id: int|null, title: string, label: string, terms: array<string, mixed>, text: string|null}>  $places
     * @param  array<string, true>  $vocabulary  Every word of every place
     * @param  list<string>  $avoid  The words and phrases to avoid
     * @param  list<string>  $claimWords
     * @param  array<string, true>  $claimTokens
     * @param  array<string, true>  $audiencePlaces  Each audience place's words, joined by a space
     * @param  list<list<string>>  $posts  Each recent post's words
     */
    private function __construct(
        private readonly array $places,
        private readonly array $vocabulary,
        private readonly array $avoid,
        private readonly array $claimWords,
        private readonly array $claimTokens,
        private readonly array $audiencePlaces,
        private readonly array $posts,
        public readonly int $entryCount,
        public readonly int $documentCount,
    ) {}

    /**
     * The check of the workspace's saved keywords, for the Strategy tab:
     * each keyword's verdict, the counts, the brand verdict, what was read
     * and what a post with no topic gets. Cached until the keywords or the
     * brand knowledge change (recent posts may lag up to an hour). Each fix
     * is the one for the viewer: $canEdit false gets the one that says who
     * can act.
     *
     * @return array{keywords: list<array<string, mixed>>, counts: array<string, int>, verdict: string|null, checked: array<string, int>, prompt: list<array{text: string, main: bool}>}
     */
    public static function page(Workspace $workspace, bool $canEdit = true): array
    {
        $profile = BrandProfile::query()->where('workspace_id', $workspace->id)->first();
        $keywords = BrandKeywords::of($profile);

        if ($keywords === []) {
            return ['keywords' => [], 'counts' => self::emptyCounts(), 'verdict' => null, 'checked' => ['entries' => 0, 'documents' => 0, 'posts' => 0, 'days' => self::recentDays()], 'prompt' => []];
        }

        $key = 'socialhq:keyword-check:'.self::RULES_VERSION.":{$workspace->id}:".md5((string) json_encode([
            $keywords,
            $profile?->updated_at?->getTimestamp(),
            KnowledgeEntry::query()->where('workspace_id', $workspace->id)->count(),
            KnowledgeEntry::query()->where('workspace_id', $workspace->id)->max('updated_at'),
            KnowledgeDocument::query()->where('workspace_id', $workspace->id)->count(),
            KnowledgeDocument::query()->where('workspace_id', $workspace->id)->max('updated_at'),
            app()->getLocale(),
        ]));

        $page = Cache::remember($key, now()->addHour(), function () use ($workspace, $profile, $keywords): array {
            $check = self::build($workspace, $profile);
            $rows = array_map(fn (array $keyword): array => $check->check($keyword['text'], $keyword['main'], $keywords), $keywords);
            $counts = self::emptyCounts();

            foreach ($rows as $row) {
                $counts[$row['verdict']]++;
            }

            return [
                'keywords' => $rows,
                'counts' => $counts,
                'verdict' => match (true) {
                    $counts[KeywordVerdict::Conflict->value] > 0 => 'needs_look',
                    $counts[KeywordVerdict::Good->value] < count($rows) => 'mostly',
                    default => 'on_brand',
                },
                'checked' => ['entries' => $check->entryCount, 'documents' => $check->documentCount, 'posts' => count($check->posts), 'days' => self::recentDays()],
                'prompt' => BrandKeywords::pick($keywords),
            ];
        });

        $page['keywords'] = array_map(fn (array $row): array => self::forViewer($row, $canEdit), $page['keywords']);

        return $page;
    }

    /**
     * The check of keywords not saved yet (a file's rows before import),
     * each against the saved ones and the others in the list.
     *
     * @param  list<string>  $texts  Cleaned keywords
     * @return list<array<string, mixed>>
     */
    public static function preview(Workspace $workspace, array $texts): array
    {
        $profile = BrandProfile::query()->where('workspace_id', $workspace->id)->first();
        $all = [...BrandKeywords::of($profile), ...array_map(fn (string $text): array => ['text' => $text, 'main' => false], $texts)];
        $check = self::build($workspace, $profile);

        return array_map(fn (string $text): array => self::forViewer($check->check($text, false, $all), true), $texts);
    }

    /**
     * A checked row with the fix for its viewer.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function forViewer(array $row, bool $canEdit): array
    {
        $row['fix'] = $canEdit ? $row['fix'] : $row['member_fix'];
        unset($row['member_fix']);

        return $row;
    }

    public static function build(Workspace $workspace, ?BrandProfile $profile): self
    {
        $places = [];

        foreach (self::PROFILE_FIELDS as $field) {
            $value = trim((string) $profile?->{$field});

            if ($value !== '') {
                $places[] = ['kind' => 'profile', 'id' => $field, 'document_id' => null, 'title' => __("socialhq.brand.profile.{$field}"), 'label' => __('socialhq.keywords.check.places.profile'), 'terms' => self::folded(Tokens::frequencies($value)), 'text' => $value];
            }
        }

        KnowledgeEntry::fillMissingTerms((string) $workspace->id);
        KnowledgeChunk::fillMissingTerms((string) $workspace->id);

        $entries = KnowledgeEntry::query()
            ->where('workspace_id', $workspace->id)
            ->usedInAi()
            ->inPromptOrder()
            ->get(['id', 'kind', 'title', 'body', 'terms']);

        foreach ($entries as $entry) {
            $places[] = ['kind' => 'entry', 'id' => $entry->id, 'document_id' => null, 'title' => $entry->title, 'label' => __("socialhq.brand.entries.kinds.{$entry->kind->value}"), 'terms' => self::folded((array) $entry->terms), 'text' => $entry->body];
        }

        $documents = KnowledgeDocument::query()->where('workspace_id', $workspace->id)->searchable()->pluck('title', 'id');

        $chunks = KnowledgeChunk::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('document_id', $documents->keys())
            ->orderBy('id')
            ->get(['id', 'document_id', 'terms']);

        foreach ($chunks as $chunk) {
            $title = (string) $documents->get($chunk->document_id);
            $places[] = ['kind' => 'document', 'id' => $chunk->id, 'document_id' => $chunk->document_id, 'title' => $title, 'label' => __('socialhq.keywords.check.places.document'), 'terms' => self::folded(Tokens::add((array) $chunk->terms, Tokens::frequencies($title))), 'text' => null];
        }

        $vocabulary = [];

        foreach ($places as $place) {
            $vocabulary += array_fill_keys(array_map(strval(...), array_keys($place['terms'])), true);
        }

        $claimWords = array_values(array_map(fn (mixed $word): string => mb_strtolower(trim((string) $word)), (array) config('socialhq.knowledge.keywords.claim_words', [])));

        return new self(
            places: $places,
            vocabulary: $vocabulary,
            avoid: self::avoidPhrases((string) $profile?->words_to_avoid),
            claimWords: $claimWords,
            claimTokens: array_fill_keys(self::tokens(implode(' ', $claimWords)), true),
            audiencePlaces: self::audiencePlaces($profile),
            posts: self::recentPosts($workspace),
            entryCount: $entries->count(),
            documentCount: $documents->count(),
        );
    }

    /**
     * One keyword's verdict, reason, fix (for whoever may change the
     * keywords, and for the others), where it was found and how many recent
     * posts say it.
     *
     * @param  list<array{text: string, main: bool}>  $all  Every keyword, for the "same words" rule and the examples in fixes
     * @return array{text: string, main: bool, verdict: string, reason: string, fix: string|null, member_fix: string|null, found: list<array{kind: string, title: string, label: string, snippet: string}>, posts: int}
     */
    public function check(string $text, bool $main, array $all): array
    {
        $words = KeywordMatcher::words($text);
        $tokens = array_values(array_unique(self::tokens($text)));
        $meaningful = array_values(array_filter($tokens, fn (string $token): bool => ! isset($this->claimTokens[$token])));
        $holders = $meaningful === [] ? [] : array_values(array_filter($this->places, fn (array $place): bool => self::holdsAll($place['terms'], $meaningful)));

        [$verdict, $reason, $fix, $memberFix] = $this->verdict($text, $words, $tokens, $meaningful, $holders, $all);

        return [
            'text' => $text,
            'main' => $main,
            'verdict' => $verdict->value,
            'reason' => $reason,
            'fix' => $fix,
            'member_fix' => $memberFix,
            'found' => $this->found($holders, $words),
            'posts' => count(array_filter($this->posts, fn (array $post): bool => KeywordMatcher::saysWords($post, $text))),
        ];
    }

    /**
     * @param  list<string>  $words
     * @param  list<string>  $tokens
     * @param  list<string>  $meaningful
     * @param  list<array<string, mixed>>  $holders
     * @param  list<array{text: string, main: bool}>  $all
     * @return array{0: KeywordVerdict, 1: string, 2: string|null, 3: string|null}  The verdict, the reason and the two fixes
     */
    private function verdict(string $text, array $words, array $tokens, array $meaningful, array $holders, array $all): array
    {
        foreach ($this->avoid as $phrase) {
            if (KeywordMatcher::saysWords($words, $phrase)) {
                return [KeywordVerdict::Conflict, __('socialhq.keywords.check.reasons.conflict', ['word' => $phrase]), ...self::fixes('conflict')];
            }
        }

        if ($meaningful !== [] && $holders === []) {
            return [KeywordVerdict::Weak, ...$this->weak($text, $words, $meaningful)];
        }

        if (count($tokens) === 1) {
            $example = $this->pairExample($tokens[0], $all);

            return [KeywordVerdict::Check, __('socialhq.keywords.check.reasons.one_word'), ...($example === null ? self::fixes('one_word') : self::fixes('one_word_example', ['example' => $example]))];
        }

        foreach ($this->claimWords as $claim) {
            $found = preg_match('/[^\p{L}\p{N}\s]/u', $claim) === 1 ? str_contains($text, $claim) : KeywordMatcher::saysWords($words, $claim);

            if ($found) {
                $other = $this->withoutClaim($text, $meaningful, $all);
                $fix = $other === null ? null : __('socialhq.keywords.check.fixes.claim_other', ['other' => $other]);

                return [KeywordVerdict::Check, __('socialhq.keywords.check.reasons.claim', ['word' => $claim]), $fix, $fix];
            }
        }

        if ($this->audiencePlaces !== [] && ($place = $this->placeOutsideAudience($words)) !== null) {
            return [KeywordVerdict::Check, __('socialhq.keywords.check.reasons.place', ['place' => $place]), ...self::fixes('place')];
        }

        $sorted = $tokens;
        sort($sorted);

        foreach ($all as $other) {
            if (BrandKeywords::sameKey($other['text']) === BrandKeywords::sameKey($text)) {
                continue;
            }

            $otherTokens = array_values(array_unique(self::tokens($other['text'])));
            sort($otherTokens);

            if ($otherTokens === $sorted) {
                return [KeywordVerdict::Check, __('socialhq.keywords.check.reasons.twin', ['other' => $other['text']]), ...self::fixes('twin')];
            }
        }

        return [KeywordVerdict::Good, $this->goodReason($holders, $words), null, null];
    }

    /**
     * A fix in its two forms: for whoever may change the keywords, and for
     * the others (it says who can).
     *
     * @param  array<string, string>  $replace
     * @return array{0: string, 1: string}
     */
    private static function fixes(string $key, array $replace = []): array
    {
        return [__("socialhq.keywords.check.fixes.{$key}", $replace), __("socialhq.keywords.check.member_fixes.{$key}", $replace)];
    }

    /**
     * Why a keyword is weak and its two fixes: the words the brand knowledge
     * has and those it never mentions, as the keyword spells them, and the
     * place outside the audience when it names one.
     *
     * @param  list<string>  $words
     * @param  list<string>  $meaningful
     * @return array{0: string, 1: string, 2: string}
     */
    private function weak(string $text, array $words, array $meaningful): array
    {
        $said = [];
        $never = [];

        foreach (preg_split('/\s+/u', $text) ?: [] as $word) {
            $wordTokens = self::tokens($word);

            if ($wordTokens === [] || array_intersect($wordTokens, $meaningful) === []) {
                continue;
            }

            if (array_diff($wordTokens, array_keys($this->vocabulary)) === []) {
                $said[] = "“{$word}”";
            } else {
                $never[] = "“{$word}”";
            }
        }

        [$reason, $fixes] = match (true) {
            $said === [] => [__('socialhq.keywords.check.reasons.weak_none'), self::fixes('weak_none')],
            $never === [] => [__('socialhq.keywords.check.reasons.weak_apart'), self::fixes('weak_apart')],
            default => [
                __('socialhq.keywords.check.reasons.weak_some', [
                    'said' => self::join($said, __('socialhq.keywords.check.and')),
                    'missing' => self::join($never, __('socialhq.keywords.check.or')),
                ]),
                self::fixes('weak_some', ['missing' => self::join($never, __('socialhq.keywords.check.and'))]),
            ],
        };

        if ($this->audiencePlaces !== [] && ($place = $this->placeOutsideAudience($words)) !== null) {
            $reason .= ' '.__('socialhq.keywords.check.reasons.place', ['place' => $place]);
        }

        return [$reason, ...$fixes];
    }

    /**
     * Another keyword with this one's word and more, main ones first: an
     * example of what to pair a one-word keyword with. One with a claim, a
     * word to avoid or a place outside the audience is no example.
     *
     * @param  list<array{text: string, main: bool}>  $all
     */
    private function pairExample(string $token, array $all): ?string
    {
        foreach ([...array_filter($all, fn (array $keyword): bool => $keyword['main']), ...array_filter($all, fn (array $keyword): bool => ! $keyword['main'])] as $other) {
            $otherTokens = array_unique(self::tokens($other['text']));
            $otherWords = KeywordMatcher::words($other['text']);

            if (count($otherTokens) < 2
                || ! in_array($token, $otherTokens, true)
                || array_intersect($otherTokens, array_keys($this->claimTokens)) !== []
                || array_filter($this->avoid, fn (string $phrase): bool => KeywordMatcher::saysWords($otherWords, $phrase)) !== []
                || ($this->audiencePlaces !== [] && $this->placeOutsideAudience($otherWords) !== null)) {
                continue;
            }

            return $other['text'];
        }

        return null;
    }

    /**
     * Another keyword that says every word of this one but the claim, with
     * no claim of its own ("specialty coffee kathmandu" for "best coffee in
     * kathmandu").
     *
     * @param  list<string>  $meaningful
     * @param  list<array{text: string, main: bool}>  $all
     */
    private function withoutClaim(string $text, array $meaningful, array $all): ?string
    {
        if ($meaningful === []) {
            return null;
        }

        foreach ($all as $other) {
            if (BrandKeywords::sameKey($other['text']) === BrandKeywords::sameKey($text)) {
                continue;
            }

            $otherTokens = self::tokens($other['text']);

            if (array_diff($meaningful, $otherTokens) === [] && array_intersect($otherTokens, array_keys($this->claimTokens)) === []) {
                return $other['text'];
            }
        }

        return null;
    }

    /**
     * Where the brand knowledge says it, named when there are few ("your
     * entry “Cold brew bottles”"), and the audience place it names.
     *
     * @param  list<array<string, mixed>>  $holders
     * @param  list<string>  $words
     */
    private function goodReason(array $holders, array $words): string
    {
        $reason = $holders === []
            ? __('socialhq.keywords.check.reasons.good_plain')
            : __('socialhq.keywords.check.reasons.good', ['places' => self::holdersSaid($holders)]);
        $place = $this->audiencePlaceSaid($words);

        return $place === null ? $reason : "{$reason} {$place}";
    }

    /**
     * "your profile, 2 entries and the document “Summer menu”": an entry or
     * a document is named when it is the only one of its kind, or one of two
     * when nothing else holds the keyword.
     *
     * @param  list<array<string, mixed>>  $holders
     */
    private static function holdersSaid(array $holders): string
    {
        $profile = array_filter($holders, fn (array $place): bool => $place['kind'] === 'profile') !== [];
        $entries = array_values(array_column(array_filter($holders, fn (array $place): bool => $place['kind'] === 'entry'), 'title'));
        $documents = array_values(array_unique(array_column(array_filter($holders, fn (array $place): bool => $place['kind'] === 'document'), 'title', 'document_id')));
        $alone = count(array_filter([$profile, $entries !== [], $documents !== []])) === 1;

        return self::join(array_values(array_filter([
            $profile ? __('socialhq.keywords.check.places.your_profile') : null,
            $entries === [] ? null : self::titles($entries, $alone, 'entry', 'entries'),
            $documents === [] ? null : self::titles($documents, $alone, 'document', 'documents'),
        ])), __('socialhq.keywords.check.and'));
    }

    /**
     * @param  list<string>  $titles
     */
    private static function titles(array $titles, bool $alone, string $one, string $many): string
    {
        return match (true) {
            count($titles) === 1 => __("socialhq.keywords.check.places.{$one}_named", ['title' => $titles[0]]),
            count($titles) === 2 && $alone => __("socialhq.keywords.check.places.{$many}_named", ['first' => $titles[0], 'second' => $titles[1]]),
            default => trans_choice("socialhq.keywords.check.places.{$many}", count($titles), ['count' => count($titles)]),
        };
    }

    /**
     * "Kathmandu is one of your audience places." for a keyword that names
     * one, or a place that is part of one ("Thamel is in Kathmandu, …").
     *
     * @param  list<string>  $words
     */
    private function audiencePlaceSaid(array $words): ?string
    {
        if ($this->audiencePlaces === []) {
            return null;
        }

        foreach (self::knownPlaces() as $place) {
            if (! KeywordMatcher::saysWords($words, $place)) {
                continue;
            }

            if (isset($this->audiencePlaces[$place])) {
                return __('socialhq.keywords.check.reasons.audience_place', ['place' => Str::title($place)]);
            }

            for ($parent = LocalPlaces::partOf($place); $parent !== null; $parent = LocalPlaces::partOf($parent)) {
                if (isset($this->audiencePlaces[$parent])) {
                    return __('socialhq.keywords.check.reasons.audience_place_part', ['place' => Str::title($place), 'parent' => Str::title($parent)]);
                }
            }
        }

        return null;
    }

    /**
     * Up to FOUND_LIMIT places that hold the keyword: profile fields, entries
     * (in prompt order), then one excerpt per document.
     *
     * @param  list<array<string, mixed>>  $holders
     * @param  list<string>  $words
     * @return list<array{kind: string, title: string, label: string, snippet: string}>
     */
    private function found(array $holders, array $words): array
    {
        $picked = [];
        $documents = [];

        foreach ($holders as $place) {
            if ($place['kind'] === 'document') {
                if (isset($documents[$place['document_id']])) {
                    continue;
                }

                $documents[$place['document_id']] = true;
            }

            $picked[] = $place;

            if (count($picked) === self::FOUND_LIMIT) {
                break;
            }
        }

        $chunkIds = array_column(array_filter($picked, fn (array $place): bool => $place['kind'] === 'document'), 'id');
        $texts = $chunkIds === [] ? collect() : KnowledgeChunk::query()->whereIn('id', $chunkIds)->pluck('text', 'id');

        return array_map(fn (array $place): array => [
            'kind' => $place['kind'],
            'title' => $place['title'],
            'label' => $place['label'],
            'snippet' => self::snippet((string) ($place['text'] ?? $texts->get($place['id'])), $words),
        ], $picked);
    }

    /**
     * About 150 characters of the text around the first of the keyword's
     * words it contains.
     *
     * @param  list<string>  $words
     */
    private static function snippet(string $text, array $words): string
    {
        $text = Str::squish($text);
        $lower = mb_strtolower($text);
        $position = null;

        foreach ($words as $word) {
            if (in_array($word, KeywordMatcher::LINKING_WORDS, true)) {
                continue;
            }

            $at = mb_strpos($lower, $word);

            if ($at !== false && ($position === null || $at < $position)) {
                $position = $at;
            }
        }

        $start = max(0, ($position ?? 0) - 50);
        $snippet = mb_substr($text, $start, 150);

        return ($start > 0 ? '…' : '').trim($snippet).($start + 150 < mb_strlen($text) ? '…' : '');
    }

    /**
     * A text's search words (Tokens) with Latin accents dropped, so "cafe"
     * finds "café".
     *
     * @return list<string>
     */
    private static function tokens(string $text): array
    {
        return array_map(KeywordMatcher::fold(...), Tokens::of($text));
    }

    /**
     * Stored word counts keyed by their accent-free form.
     *
     * @param  array<string, mixed>  $terms
     * @return array<string, true>
     */
    private static function folded(array $terms): array
    {
        $folded = [];

        foreach (array_keys($terms) as $term) {
            $folded[KeywordMatcher::fold((string) $term)] = true;
        }

        return $folded;
    }

    /**
     * @param  array<string, mixed>  $terms
     * @param  list<string>  $tokens
     */
    private static function holdsAll(array $terms, array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (! array_key_exists($token, $terms)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A country or city name the keyword says that isn't an audience place,
     * as it would be written ("Lalitpur").
     *
     * @param  list<string>  $words
     */
    private function placeOutsideAudience(array $words): ?string
    {
        foreach (self::knownPlaces() as $place) {
            if (! $this->inAudience($place) && KeywordMatcher::saysWords($words, $place)) {
                return Str::title($place);
            }
        }

        return null;
    }

    /**
     * An audience place, or part of one (Thamel, when Kathmandu is).
     */
    private function inAudience(string $place): bool
    {
        for ($current = $place; $current !== null; $current = LocalPlaces::partOf($current)) {
            if (isset($this->audiencePlaces[$current])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Country names, the cities of the time zone database and the local
     * places (LocalPlaces), as words joined by a space, longest first (so
     * "new york" wins over "york").
     *
     * @return list<string>
     */
    private static function knownPlaces(): array
    {
        static $places = null;

        if ($places !== null) {
            return $places;
        }

        $notPlaces = array_fill_keys(array_map(fn (mixed $word): string => mb_strtolower((string) $word), (array) config('socialhq.knowledge.keywords.not_places', [])), true);
        $names = [...array_map(fn (array $country): string => $country['name'], AudienceCountries::all()), ...LocalPlaces::all()];

        foreach (DateTimeZone::listIdentifiers() as $zone) {
            $names[] = self::zoneCity($zone);
        }

        $places = collect($names)
            ->map(fn (string $name): string => implode(' ', KeywordMatcher::words($name)))
            ->filter(fn (string $name): bool => $name !== '' && ! isset($notPlaces[$name]))
            ->unique()
            ->sortByDesc(fn (string $name): int => mb_strlen($name))
            ->values()
            ->all();

        return $places;
    }

    /**
     * The audience's countries, cities and time zone cities. A whole country
     * (a place without a city) covers its towns too: its local places and
     * the cities of its time zones.
     *
     * @return array<string, true>
     */
    private static function audiencePlaces(?BrandProfile $profile): array
    {
        $places = [];

        foreach (Audience::locations($profile) as $location) {
            $names = [AudienceCountries::name($location['country']), (string) $location['city'], self::zoneCity($location['timezone'])];

            if ($location['city'] === null) {
                $names = [
                    ...$names,
                    ...LocalPlaces::inCountry($location['country']),
                    ...array_map(self::zoneCity(...), DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $location['country'])),
                ];
            }

            foreach ($names as $name) {
                $key = implode(' ', KeywordMatcher::words($name));

                if ($key !== '') {
                    $places[$key] = true;
                }
            }
        }

        return $places;
    }

    private static function zoneCity(string $zone): string
    {
        return str_replace('_', ' ', Str::afterLast($zone, '/'));
    }

    /**
     * "Words and phrases to avoid", split on commas, semicolons and lines.
     *
     * @return list<string>
     */
    private static function avoidPhrases(string $value): array
    {
        return collect(preg_split('/[\n,;]+/u', $value) ?: [])
            ->map(fn (string $phrase): string => Str::squish(trim($phrase, " \t\"'“”‘’.")))
            ->filter(fn (string $phrase): bool => KeywordMatcher::words($phrase) !== [])
            ->map(fn (string $phrase): string => mb_strtolower($phrase))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The words of the workspace's posts scheduled or published in the last
     * recent_days, newest first.
     *
     * @return list<list<string>>
     */
    private static function recentPosts(Workspace $workspace): array
    {
        $since = now()->subDays(self::recentDays());

        return Post::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('status', [PostStatus::Scheduled, PostStatus::Publishing, PostStatus::Published])
            ->where(fn (Builder $query) => $query->where('published_at', '>=', $since)->orWhere('scheduled_at', '>=', $since))
            ->latest('id')
            ->limit(self::POSTS_LIMIT)
            ->pluck('content')
            ->map(fn (mixed $content): array => KeywordMatcher::words(html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", (string) $content)))))
            ->values()
            ->all();
    }

    private static function recentDays(): int
    {
        return max(1, (int) config('socialhq.knowledge.keywords.recent_days', 90));
    }

    /**
     * @return array<string, int>
     */
    private static function emptyCounts(): array
    {
        return array_fill_keys(array_map(fn (KeywordVerdict $verdict): string => $verdict->value, KeywordVerdict::cases()), 0);
    }

    /**
     * "a", "a and b", "a, b and c".
     *
     * @param  list<string>  $items
     */
    private static function join(array $items, string $last): string
    {
        if (count($items) < 2) {
            return implode('', $items);
        }

        return implode(', ', array_slice($items, 0, -1))." {$last} ".end($items);
    }
}
