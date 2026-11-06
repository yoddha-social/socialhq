<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge;

use App\Models\User;
use App\Models\Workspace;

/**
 * What the AI is told about a workspace's brand: its profile, then the
 * entries marked for AI (pinned first, then newest), until the budget is
 * spent.
 *
 * Every AI feature that writes for a brand calls forPrompt() with the
 * workspace it works for, passed explicitly. The profile gets at most half
 * of the budget (each field shortened fairly when it is longer), so entries
 * always have room. Sizes are counted with size(), never mb_strlen().
 */
final class BrandKnowledge
{
    private const PROFILE_LABELS = [
        'about' => 'What the brand is',
        'audience' => 'Audience',
        'voice' => 'Voice and tone',
        'words_to_use' => 'Words and phrases to use',
        'words_to_avoid' => 'Words and phrases to avoid',
    ];

    private const ENTRIES_HEADING = 'What to know:';

    private const EXAMPLES_HEADING = "Example posts in the brand's voice (match their tone and style; don't reuse their facts, prices or dates):";

    private const ELLIPSIS = '…';

    public static function canEdit(User $user, Workspace $workspace): bool
    {
        return $user->can('update', $workspace);
    }

    public static function canView(User $user, Workspace $workspace): bool
    {
        return $user->can('createPost', $workspace);
    }

    /**
     * The brand knowledge block for one AI prompt.
     *
     * @param  string|null  $focus  What the prompt is about (the request). Not used yet: it
     *                              will pick the entries that match once embeddings land.
     */
    public static function forPrompt(Workspace $workspace, ?string $focus = null): string
    {
        return self::assemble($workspace, $focus)->text;
    }

    /**
     * The prompt text plus what made it in: which entries, the space used.
     */
    public static function assemble(Workspace $workspace, ?string $focus = null): KnowledgePrompt
    {
        $budget = self::budget();
        [$profileText, $profileTrimmed] = self::profileSection($workspace, intdiv($budget, 2));
        $used = self::size($profileText);

        // Both headings, and the blank lines between sections, come out of the entries' room.
        $room = $budget - $used - self::size(self::ENTRIES_HEADING) - self::size(self::EXAMPLES_HEADING) - 4;

        $entries = KnowledgeEntry::query()
            ->where('workspace_id', $workspace->id)
            ->usedInAi()
            ->inPromptOrder()
            ->get(['id', 'kind', 'title', 'body']);

        $facts = [];
        $examples = [];
        $fits = [];
        $left = $room;

        foreach ($entries as $entry) {
            $fact = self::fact($entry);
            $cost = self::size($fact) + 1;

            if ($cost > $room) {
                $fits[$entry->id] = PromptFit::TooLong;
            } elseif ($cost > $left) {
                $fits[$entry->id] = PromptFit::NoRoom;
            } else {
                $fits[$entry->id] = PromptFit::Included;
                $left -= $cost;

                if ($entry->kind->isExample()) {
                    $examples[] = $fact;
                } else {
                    $facts[] = $fact;
                }
            }
        }

        $sections = array_filter([
            $profileText,
            $facts === [] ? '' : self::ENTRIES_HEADING."\n".implode("\n", $facts),
            $examples === [] ? '' : self::EXAMPLES_HEADING."\n".implode("\n", $examples),
        ]);
        $text = implode("\n\n", $sections);

        return new KnowledgePrompt($text, self::size($text), $budget, $profileTrimmed, $fits);
    }

    /**
     * How much of the budget a text takes. Characters outside Latin-1
     * (Devanagari, emoji) count double: they cost the model several times
     * more tokens per character than English. A rough guard, not a token count.
     */
    public static function size(string $text): int
    {
        return mb_strlen($text) + (int) preg_match_all('/[^\x{0000}-\x{00FF}]/u', $text);
    }

    public static function budget(): int
    {
        return max(500, (int) config('socialhq.knowledge.prompt_characters', 6000));
    }

    /**
     * The profile as "Label:\nvalue" sections, shortened to fit $share.
     *
     * @return array{0: string, 1: bool} The text, and whether a field was shortened
     */
    private static function profileSection(Workspace $workspace, int $share): array
    {
        $profile = BrandProfile::query()->where('workspace_id', $workspace->id)->first();

        $values = [];

        foreach (self::PROFILE_LABELS as $field => $label) {
            $value = self::clean((string) $profile?->{$field});

            if ($value !== '') {
                $values[$field] = $value;
            }
        }

        if ($values === []) {
            return ['', false];
        }

        // What the labels and the blank lines between sections take.
        $overhead = 0;

        foreach (array_keys($values) as $field) {
            $overhead += self::size(self::PROFILE_LABELS[$field].":\n");
        }

        $overhead += 2 * (count($values) - 1);

        $allowances = self::shareFairly(
            array_map(self::size(...), $values),
            max(0, $share - $overhead),
        );

        $trimmed = false;
        $sections = [];

        foreach ($values as $field => $value) {
            if (self::size($value) > $allowances[$field]) {
                $value = self::shorten($value, $allowances[$field]);
                $trimmed = true;
            }

            if ($value !== '') {
                $sections[] = self::PROFILE_LABELS[$field].":\n{$value}";
            }
        }

        return [implode("\n\n", $sections), $trimmed];
    }

    /**
     * Splits $total between fields: a short field keeps all it needs, and
     * what it leaves is shared by the longer ones.
     *
     * @param  array<string, int>  $needs
     * @return array<string, int>
     */
    private static function shareFairly(array $needs, int $total): array
    {
        asort($needs);

        $allowances = [];
        $remaining = count($needs);

        foreach ($needs as $field => $need) {
            $fairShare = intdiv($total, $remaining);
            $allowances[$field] = min($need, $fairShare);
            $total -= $allowances[$field];
            $remaining--;
        }

        return $allowances;
    }

    /**
     * Cuts a text to at most $size (counted with size()), ending with an ellipsis.
     */
    private static function shorten(string $text, int $size): string
    {
        $room = $size - self::size(self::ELLIPSIS);

        if ($room <= 0) {
            return '';
        }

        $kept = '';
        $used = 0;

        foreach (mb_str_split($text) as $character) {
            $cost = self::size($character);

            if ($used + $cost > $room) {
                break;
            }

            $kept .= $character;
            $used += $cost;
        }

        return rtrim($kept).self::ELLIPSIS;
    }

    private static function fact(KnowledgeEntry $entry): string
    {
        $title = self::clean($entry->title);
        $body = str_replace("\n", "\n  ", self::clean($entry->body));

        if ($entry->kind->isExample()) {
            return "- {$title}\n  {$body}";
        }

        return "- {$entry->kind->promptLabel()}: {$title}\n  {$body}";
    }

    /**
     * Trims a value and breaks up runs of three or more double quotes, so
     * no text can close the """ block the prompt wraps it in.
     */
    private static function clean(string $value): string
    {
        return (string) preg_replace_callback(
            '/"{3,}/',
            fn (array $quotes): string => implode(' ', mb_str_split($quotes[0])),
            trim($value),
        );
    }
}
