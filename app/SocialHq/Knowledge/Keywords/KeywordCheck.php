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
 * Every verdict is a warning; every keyword still goes to the AI.
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
    private const int RULES_VERSION = 3;

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
     * brand knowledge change (recent posts may lag up to an hour).
     *
     * @return array{keywords: list<array<string, mixed>>, counts: array<string, int>, verdict: string|null, checked: array<string, int>, prompt: list<array{text: string, main: bool}>}
     */
    public static function page(Workspace $workspace): array
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

        return Cache::remember($key, now()->addHour(), function () use ($workspace, $profile, $keywords): array {
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

        return array_map(fn (string $text): array => $check->check($text, false, $all), $texts);
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
     * One keyword's verdict, reason, fix, where it was found and how many
     * recent posts say it.
     *
     * @param  list<array{text: string, main: bool}>  $all  Every keyword, for the "same words" rule
     * @return array{text: string, main: bool, verdict: string, reason: string, fix: string|null, found: list<array{kind: string, title: string, label: string, snippet: string}>, posts: int}
     */
    public function check(string $text, bool $main, array $all): array
    {
        $words = KeywordMatcher::words($text);
        $tokens = array_values(array_unique(self::tokens($text)));
        $meaningful = array_values(array_filter($tokens, fn (string $token): bool => ! isset($this->claimTokens[$token])));
        $holders = $meaningful === [] ? [] : array_values(array_filter($this->places, fn (array $place): bool => self::holdsAll($place['terms'], $meaningful)));

        [$verdict, $reason, $fix] = $this->verdict($text, $words, $tokens, $meaningful, $holders, $all);

        return [
            'text' => $text,
            'main' => $main,
            'verdict' => $verdict->value,
            'reason' => $reason,
            'fix' => $fix,
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
     * @return array{0: KeywordVerdict, 1: string, 2: string|null}
     */
    private function verdict(string $text, array $words, array $tokens, array $meaningful, array $holders, array $all): array
    {
        foreach ($this->avoid as $phrase) {
            if (KeywordMatcher::saysWords($words, $phrase)) {
                return [KeywordVerdict::Conflict, __('socialhq.keywords.check.reasons.conflict', ['word' => $phrase]), __('socialhq.keywords.check.fixes.conflict')];
            }
        }

        if ($meaningful !== [] && $holders === []) {
            return [KeywordVerdict::Weak, $this->weakReason($text, $meaningful), __('socialhq.keywords.check.fixes.weak')];
        }

        if (count($tokens) === 1) {
            return [KeywordVerdict::Check, __('socialhq.keywords.check.reasons.one_word'), __('socialhq.keywords.check.fixes.one_word')];
        }

        foreach ($this->claimWords as $claim) {
            $found = preg_match('/[^\p{L}\p{N}\s]/u', $claim) === 1 ? str_contains($text, $claim) : KeywordMatcher::saysWords($words, $claim);

            if ($found) {
                return [KeywordVerdict::Check, __('socialhq.keywords.check.reasons.claim', ['word' => $claim]), null];
            }
        }

        if ($this->audiencePlaces !== [] && ($place = $this->placeOutsideAudience($words)) !== null) {
            return [KeywordVerdict::Check, __('socialhq.keywords.check.reasons.place', ['place' => $place]), __('socialhq.keywords.check.fixes.place')];
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
                return [KeywordVerdict::Check, __('socialhq.keywords.check.reasons.twin', ['other' => $other['text']]), __('socialhq.keywords.check.fixes.twin')];
            }
        }

        return [KeywordVerdict::Good, $this->goodReason($holders), null];
    }

    /**
     * Names the keyword's words the brand knowledge has and those it never
     * mentions, as the keyword spells them.
     *
     * @param  list<string>  $meaningful
     */
    private function weakReason(string $text, array $meaningful): string
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

        if ($said === []) {
            return __('socialhq.keywords.check.reasons.weak_none');
        }

        if ($never === []) {
            return __('socialhq.keywords.check.reasons.weak_apart');
        }

        return __('socialhq.keywords.check.reasons.weak_some', [
            'said' => self::join($said, __('socialhq.keywords.check.and')),
            'missing' => self::join($never, __('socialhq.keywords.check.or')),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $holders
     */
    private function goodReason(array $holders): string
    {
        if ($holders === []) {
            return __('socialhq.keywords.check.reasons.good_plain');
        }

        $profile = count(array_filter($holders, fn (array $place): bool => $place['kind'] === 'profile')) > 0;
        $entries = count(array_filter($holders, fn (array $place): bool => $place['kind'] === 'entry'));
        $documents = count(array_unique(array_column(array_filter($holders, fn (array $place): bool => $place['kind'] === 'document'), 'document_id')));

        $parts = array_values(array_filter([
            $profile ? __('socialhq.keywords.check.places.your_profile') : null,
            $entries > 0 ? trans_choice('socialhq.keywords.check.places.entries', $entries, ['count' => $entries]) : null,
            $documents > 0 ? trans_choice('socialhq.keywords.check.places.documents', $documents, ['count' => $documents]) : null,
        ]));

        return __('socialhq.keywords.check.reasons.good', ['places' => self::join($parts, __('socialhq.keywords.check.and'))]);
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
