<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Keywords;

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Knowledge\BrandKnowledge;
use App\SocialHq\Knowledge\KnowledgeEntry;
use App\SocialHq\Knowledge\KnowledgeKind;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A brand's SEO keyword catalogue (`shq_keywords`, with `shq_keyword_tags`):
 * up to 5 main keywords any post may get, and a library of up to
 * `max_supporting` more, each tagged with pillars, products or services and
 * occasions (none: general). For each post the app picks the few that fit
 * (KeywordPicker); only those go to the AI.
 *
 * Every keyword is used whatever the keyword check says of it (owner
 * decision 2026-11-23: its verdicts are warnings).
 */
final class BrandKeywords
{
    public const int MIN_LENGTH = 2;

    public const int MAX_LENGTH = 60;

    /** At most this many tags on one keyword. */
    public const int MAX_TAGS = 8;

    public static function maxMain(): int
    {
        return max(0, (int) config('socialhq.knowledge.keywords.max_main', 5));
    }

    /**
     * The library's size (keywords that aren't main).
     */
    public static function maxSupporting(): int
    {
        return max(0, (int) config('socialhq.knowledge.keywords.max_supporting', 75));
    }

    public static function maxTotal(): int
    {
        return self::maxMain() + self::maxSupporting();
    }

    /**
     * The workspace's keywords, main and library, in the admin's order.
     *
     * @return list<array{text: string, main: bool}>
     */
    public static function forWorkspace(Workspace $workspace): array
    {
        return array_map(fn (array $keyword): array => ['text' => $keyword['text'], 'main' => $keyword['main']], self::catalogue($workspace));
    }

    /**
     * The catalogue: each keyword in the admin's order with its tags and the
     * day it was added.
     *
     * @return list<array{text: string, main: bool, tags: list<array{kind: string, value: string|null, entry_id: int|null, from: int|null, to: int|null, label: string}>, added_at: CarbonImmutable}>
     */
    public static function catalogue(Workspace $workspace): array
    {
        return Keyword::query()
            ->where('workspace_id', $workspace->id)
            ->with('tags.entry:id,title')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (Keyword $keyword): array => [
                'text' => $keyword->text,
                'main' => $keyword->main,
                'tags' => $keyword->tags->map(self::presentTag(...))->values()->all(),
                'added_at' => CarbonImmutable::parse($keyword->created_at),
            ])
            ->values()
            ->all();
    }

    /**
     * A stored tag as the pages, the picker and agents read it, with its label.
     *
     * @return array{kind: string, value: string|null, entry_id: int|null, from: int|null, to: int|null, label: string}
     */
    public static function presentTag(KeywordTag $tag): array
    {
        return [
            'kind' => $tag->kind->value,
            'value' => $tag->value,
            'entry_id' => $tag->entry_id,
            'from' => $tag->month_from,
            'to' => $tag->month_to,
            'label' => match ($tag->kind) {
                KeywordTagKind::Product => (string) $tag->entry?->title,
                KeywordTagKind::Months => Occasion::monthsLabel((int) $tag->month_from, (int) $tag->month_to),
                KeywordTagKind::Event => (string) ($tag->label ?? $tag->value),
                KeywordTagKind::Pillar => (string) $tag->value,
            },
        ];
    }

    public static function isEmpty(Workspace $workspace): bool
    {
        return ! Keyword::query()->where('workspace_id', $workspace->id)->exists();
    }

    /**
     * Saves the whole list, as the Strategy tab sends it after every change.
     * A keyword that stays keeps its row (and the day it was added); its
     * main flag, place and tags follow the list. Product tags of entries
     * that aren't this brand's products are dropped.
     *
     * @param  array<mixed>  $rows
     */
    public static function save(Workspace $workspace, array $rows): void
    {
        $keywords = self::normalize($rows);
        $products = KnowledgeEntry::query()
            ->where('workspace_id', $workspace->id)
            ->where('kind', KnowledgeKind::Product)
            ->pluck('id')
            ->flip();

        DB::transaction(function () use ($workspace, $keywords, $products): void {
            $existing = Keyword::query()->where('workspace_id', $workspace->id)->with('tags')->lockForUpdate()->get()->keyBy('match_key');
            $kept = [];

            foreach ($keywords as $position => $keyword) {
                $key = self::sameKey($keyword['text']);
                $row = $existing->get($key) ?? new Keyword(['workspace_id' => $workspace->id, 'match_key' => $key]);
                $row->fill(['text' => $keyword['text'], 'main' => $keyword['main'], 'position' => $position])->save();
                $kept[] = $row->id;

                $tags = array_values(array_filter($keyword['tags'], fn (array $tag): bool => $tag['kind'] !== KeywordTagKind::Product->value || $products->has($tag['entry_id'])));

                $stored = $row->relationLoaded('tags') ? $row->tags->map(fn (KeywordTag $tag): string => self::tagState([
                    'kind' => $tag->kind->value, 'value' => $tag->value, 'entry_id' => $tag->entry_id, 'from' => $tag->month_from, 'to' => $tag->month_to, 'label' => $tag->label,
                ]))->all() : [];

                if (array_map(self::tagState(...), $tags) === $stored) {
                    continue;
                }

                $row->tags()->delete();

                foreach ($tags as $tag) {
                    $row->tags()->create([
                        'kind' => $tag['kind'],
                        'value' => $tag['value'],
                        'label' => $tag['kind'] === KeywordTagKind::Event->value ? $tag['label'] : null,
                        'entry_id' => $tag['entry_id'],
                        'month_from' => $tag['from'],
                        'month_to' => $tag['to'],
                    ]);
                }
            }

            Keyword::query()->where('workspace_id', $workspace->id)->whereNotIn('id', $kept)->delete();
        });
    }

    /**
     * What an AI endpoint adds to its answer after writing new text: whether
     * the brand has no keywords (the page shows a light note) and whether
     * this person may add them, by the rule that guards the keywords card
     * (BrandKnowledge::canEdit), so the note links only those it lets in.
     *
     * @return array{keywords_missing: bool, can_add_keywords: bool}
     */
    public static function missingNote(User $user, Workspace $workspace, bool $newText = true): array
    {
        $missing = $newText && self::isEmpty($workspace);

        return ['keywords_missing' => $missing, 'can_add_keywords' => $missing && BrandKnowledge::canEdit($user, $workspace)];
    }

    /**
     * Everything an AI endpoint adds to its answer about keywords: the note
     * when the brand has none (missingNote()), else which keywords of the
     * whole catalogue the text says (main ones first, then the library, in
     * the admin's order) and whether a main one is in the caption's first
     * line. Found by KeywordMatcher, never asked of the model.
     *
     * keywords_used is null when there is no line to show: the brand has no
     * keywords, or a rewrite of the person's own text (never asked to add
     * any) says none.
     *
     * @param  string  $caption  The caption (its first line is checked), as text or HTML
     * @param  string  $more  The rest of the text that is posted (hashtags, a video script)
     * @return array{keywords_missing: bool, can_add_keywords: bool, keywords_used: list<string>|null, main_in_first_line: bool}
     */
    public static function forAnswer(User $user, Workspace $workspace, string $caption, string $more = '', bool $newText = true): array
    {
        $keywords = self::forWorkspace($workspace);
        $ordered = [...array_filter($keywords, fn (array $keyword): bool => $keyword['main']), ...array_filter($keywords, fn (array $keyword): bool => ! $keyword['main'])];
        $caption = self::plain($caption);
        $used = KeywordMatcher::in(trim("{$caption}\n{$more}"), array_column($ordered, 'text'));
        $firstLine = Str::before(ltrim($caption), "\n");
        $mainTexts = array_column(array_filter($keywords, fn (array $keyword): bool => $keyword['main']), 'text');

        return [
            ...self::missingNote($user, $workspace, $newText),
            'keywords_used' => $keywords === [] || (! $newText && $used === []) ? null : $used,
            'main_in_first_line' => KeywordMatcher::in($firstLine, $mainTexts) !== [],
        ];
    }

    /**
     * The catalogue keywords a text already says (main ones first), for a
     * rewrite that is only told to keep them.
     *
     * @return list<array{text: string, main: bool}>
     */
    public static function saidIn(Workspace $workspace, string $text): array
    {
        $keywords = self::forWorkspace($workspace);
        $ordered = [...array_filter($keywords, fn (array $keyword): bool => $keyword['main']), ...array_filter($keywords, fn (array $keyword): bool => ! $keyword['main'])];
        $said = array_flip(KeywordMatcher::in(self::plain($text), array_column($ordered, 'text')));

        return array_values(array_filter($ordered, fn (array $keyword): bool => isset($said[$keyword['text']])));
    }

    /**
     * An editor's HTML as text, a paragraph or break per line.
     */
    public static function plain(string $text): string
    {
        return html_entity_decode(strip_tags((string) preg_replace('#</p>|<br\s*/?>#i', "\n", $text)));
    }

    /**
     * The keywords as plain lines, for the sources UnsupportedDetails checks against.
     *
     * @param  list<array{text: string, main: bool}>  $keywords
     */
    public static function sourceText(array $keywords): string
    {
        return implode("\n", array_column($keywords, 'text'));
    }

    /**
     * A keyword as stored: one line, lowercased, commas and semicolons made
     * spaces (they separate keywords in a list or a CSV, so a quoted CSV cell
     * can't keep one), a leading # dropped, runs of """ broken up (no text may
     * close the prompt's quoted block).
     */
    public static function clean(string $text): string
    {
        $text = Str::lower(Str::squish(str_replace([',', ';'], ' ', $text)));
        $text = ltrim($text, '#');
        $text = (string) preg_replace_callback('/"{3,}/', fn (array $quotes): string => implode(' ', mb_str_split($quotes[0])), $text);

        return Str::squish($text);
    }

    /**
     * Two keywords are the same when their words are, case and accents ignored.
     */
    public static function sameKey(string $text): string
    {
        return implode(' ', KeywordMatcher::words($text));
    }

    /**
     * A submitted list made safe: cleaned, too short or too long dropped,
     * duplicates dropped (the first one stays), main ones past the limit
     * moved to the library, the library cut to its limit, and each
     * keyword's tags cleaned (normalizeTags()).
     *
     * @param  array<mixed>  $rows
     * @return list<array{text: string, main: bool, tags: list<array{kind: string, value: string|null, entry_id: int|null, from: int|null, to: int|null, label: string|null}>}>
     */
    public static function normalize(array $rows): array
    {
        $kept = [];
        $seen = [];
        $mainCount = 0;

        foreach ($rows as $row) {
            $text = self::clean((string) data_get($row, 'text'));
            $key = self::sameKey($text);

            if (mb_strlen($text) < self::MIN_LENGTH || mb_strlen($text) > self::MAX_LENGTH || $key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $main = (bool) data_get($row, 'main', false) && $mainCount < self::maxMain();
            $mainCount += $main ? 1 : 0;
            $kept[] = ['text' => $text, 'main' => $main, 'tags' => self::normalizeTags((array) data_get($row, 'tags', []))];
        }

        $supporting = 0;

        return array_values(array_filter($kept, function (array $keyword) use (&$supporting): bool {
            if ($keyword['main']) {
                return true;
            }

            return ++$supporting <= self::maxSupporting();
        }));
    }

    /**
     * A keyword's tags made safe: known kinds only, each with what it needs
     * (a pillar's name, a product's entry, an event's key and name, two
     * months), the same tag once, MAX_TAGS at most. An event's key is kept
     * without its year (Occasion::key()).
     *
     * @param  array<mixed>  $tags
     * @return list<array{kind: string, value: string|null, entry_id: int|null, from: int|null, to: int|null, label: string|null}>
     */
    public static function normalizeTags(array $tags): array
    {
        $kept = [];

        foreach ($tags as $tag) {
            $kind = KeywordTagKind::tryFrom((string) data_get($tag, 'kind'));
            $value = Str::limit(Str::squish((string) data_get($tag, 'value')), 160, '');
            $entry = filter_var(data_get($tag, 'entry_id'), FILTER_VALIDATE_INT);
            $from = filter_var(data_get($tag, 'from'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]);
            $to = filter_var(data_get($tag, 'to'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]);

            $clean = match ($kind) {
                KeywordTagKind::Pillar => $value === '' ? null : ['value' => $value],
                KeywordTagKind::Product => $entry === false ? null : ['entry_id' => $entry],
                KeywordTagKind::Event => $value === '' ? null : ['value' => Occasion::key(Str::slug($value)), 'label' => Str::limit(Str::squish((string) data_get($tag, 'label')), 160, '') ?: $value],
                KeywordTagKind::Months => $from === false || $to === false ? null : ['from' => $from, 'to' => $to],
                null => null,
            };

            if ($clean === null || $kind === null) {
                continue;
            }

            $tag = ['kind' => $kind->value, 'value' => null, 'entry_id' => null, 'from' => null, 'to' => null, 'label' => null, ...$clean];
            $signature = self::tagSignature($tag);

            if (! isset($kept[$signature]) && count($kept) < self::MAX_TAGS) {
                $kept[$signature] = $tag;
            }
        }

        return array_values($kept);
    }

    /**
     * What makes a tag the same tag: its kind and what it points at.
     *
     * @param  array<string, mixed>  $tag
     */
    private static function tagSignature(array $tag): string
    {
        return match ($tag['kind']) {
            KeywordTagKind::Pillar->value => 'pillar:'.Str::lower((string) $tag['value']),
            KeywordTagKind::Product->value => "product:{$tag['entry_id']}",
            KeywordTagKind::Event->value => "event:{$tag['value']}",
            default => "months:{$tag['from']}-{$tag['to']}",
        };
    }

    /**
     * Everything a stored tag holds, to tell whether a save changes it.
     *
     * @param  array<string, mixed>  $tag
     */
    private static function tagState(array $tag): string
    {
        $label = $tag['kind'] === KeywordTagKind::Event->value ? (string) $tag['label'] : '';

        return self::tagSignature($tag)."|{$label}";
    }
}
