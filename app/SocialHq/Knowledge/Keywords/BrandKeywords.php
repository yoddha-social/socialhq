<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Keywords;

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Knowledge\BrandKnowledge;
use App\SocialHq\Knowledge\BrandProfile;
use App\SocialHq\Knowledge\Search\Tokens;
use Illuminate\Support\Str;

/**
 * A brand's SEO keywords (`shq_brand_profiles.keywords`): main ones (the AI
 * tries one in every caption) and supporting ones (used when a post is about
 * them), in the order the admin set.
 *
 * Every keyword goes to the AI, whatever the keyword check says of it (owner
 * decision 2026-11-23: its verdicts are warnings). A prompt gets them outside
 * the brand knowledge budget, at most `knowledge.keywords.prompt` of them.
 */
final class BrandKeywords
{
    public const int MIN_LENGTH = 2;

    public const int MAX_LENGTH = 60;

    public static function maxMain(): int
    {
        return max(0, (int) config('socialhq.knowledge.keywords.max_main', 5));
    }

    public static function maxSupporting(): int
    {
        return max(0, (int) config('socialhq.knowledge.keywords.max_supporting', 25));
    }

    public static function maxTotal(): int
    {
        return self::maxMain() + self::maxSupporting();
    }

    /**
     * The workspace's keywords, main and supporting, in the admin's order.
     *
     * @return list<array{text: string, main: bool}>
     */
    public static function forWorkspace(Workspace $workspace): array
    {
        return self::of(BrandProfile::query()->where('workspace_id', $workspace->id)->first());
    }

    /**
     * @return list<array{text: string, main: bool}>
     */
    public static function of(?BrandProfile $profile): array
    {
        return self::normalize((array) ($profile?->keywords ?? []));
    }

    public static function isEmpty(Workspace $workspace): bool
    {
        return self::forWorkspace($workspace) === [];
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
     * when the brand has none (missingNote()), else which keywords the text
     * says (main ones first, then supporting, in the admin's order) and
     * whether a main one is in the caption's first line. Found by
     * KeywordMatcher, never asked of the model.
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
     * An editor's HTML as text, a paragraph or break per line.
     */
    private static function plain(string $text): string
    {
        return html_entity_decode(strip_tags((string) preg_replace('#</p>|<br\s*/?>#i', "\n", $text)));
    }

    /**
     * What one prompt is given: the main keywords, then the supporting ones
     * that share the most words with the focus (in the admin's order on a
     * tie), at most `knowledge.keywords.prompt` in all.
     *
     * @param  string|null  $focus  What the text is about, as for BrandKnowledge::forPrompt()
     * @return list<array{text: string, main: bool}>
     */
    public static function forPrompt(Workspace $workspace, ?string $focus = null): array
    {
        return self::pick(self::forWorkspace($workspace), $focus);
    }

    /**
     * @param  list<array{text: string, main: bool}>  $keywords
     * @return list<array{text: string, main: bool}>
     */
    public static function pick(array $keywords, ?string $focus = null): array
    {
        $limit = max(0, (int) config('socialhq.knowledge.keywords.prompt', 8));
        $main = array_values(array_filter($keywords, fn (array $keyword): bool => $keyword['main']));
        $supporting = array_values(array_filter($keywords, fn (array $keyword): bool => ! $keyword['main']));
        $focusWords = array_fill_keys(Tokens::of((string) $focus), true);

        if ($focusWords !== []) {
            $scores = array_map(
                fn (array $keyword): int => count(array_filter(Tokens::of($keyword['text']), fn (string $token): bool => isset($focusWords[$token]))),
                $supporting,
            );
            $order = array_keys($supporting);
            usort($order, fn (int $left, int $right): int => [$scores[$right], $left] <=> [$scores[$left], $right]);
            $supporting = array_map(fn (int $index): array => $supporting[$index], $order);
        }

        return array_slice([...$main, ...$supporting], 0, $limit);
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
     * A stored or submitted list made safe: cleaned, too short or too long
     * dropped, duplicates dropped (the first one stays), main ones past the
     * limit moved to supporting, and the whole list cut to the limit.
     *
     * @param  array<mixed>  $rows
     * @return list<array{text: string, main: bool}>
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
            $kept[] = ['text' => $text, 'main' => $main];
        }

        $supporting = 0;

        return array_values(array_filter($kept, function (array $keyword) use (&$supporting): bool {
            if ($keyword['main']) {
                return true;
            }

            return ++$supporting <= self::maxSupporting();
        }));
    }
}
