<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge;

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Knowledge\Documents\KnowledgeChunk;
use App\SocialHq\Knowledge\Search\KnowledgeMatch;
use App\SocialHq\Knowledge\Search\KnowledgeSearch;
use Illuminate\Support\Collection;

/**
 * What the AI is told about a workspace's brand: its profile, then the
 * entries marked for AI (pinned first; with a focus, the entries and
 * document excerpts that match the request next; then newest), until the
 * budget is spent.
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

    private const DOCUMENTS_HEADING = 'From documents:';

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
     * @param  string|null  $focus  What the prompt is about (the request): the entries and
     *                              document excerpts that match it come right after the pinned
     *                              entries. Without it, documents are left out.
     */
    public static function forPrompt(Workspace $workspace, ?string $focus = null): string
    {
        return self::assemble($workspace, $focus)->text;
    }

    /**
     * The prompt text plus what made it in: which entries and excerpts, the space used.
     *
     * Order: the profile (at most half), pinned entries, then with a focus
     * the entries and excerpts that match it (best first), then the other
     * entries, newest first, until the budget is spent.
     */
    public static function assemble(Workspace $workspace, ?string $focus = null): KnowledgePrompt
    {
        $focus = trim((string) $focus);
        $focused = $focus !== '';
        $budget = self::budget($focused);
        [$profileText, $profileTrimmed] = self::profileSection($workspace, intdiv($budget, 2));
        $used = self::size($profileText);

        // The headings, and the blank lines between sections, come out of the entries' room.
        $room = $budget - $used - self::size(self::ENTRIES_HEADING) - self::size(self::EXAMPLES_HEADING) - 4;

        if ($focused) {
            $room -= self::size(self::DOCUMENTS_HEADING) + 2;
        }

        $entries = KnowledgeEntry::query()
            ->where('workspace_id', $workspace->id)
            ->usedInAi()
            ->inPromptOrder()
            ->get(['id', 'kind', 'title', 'body', 'pinned']);

        [$queue, $matched] = $focused ? self::focusedOrder($workspace, $focus, $entries) : [$entries->all(), []];

        $facts = [];
        $examples = [];
        $documents = [];
        $fits = [];
        $parts = [];
        $left = $room;

        foreach ($queue as $item) {
            if ($item instanceof KnowledgeChunk) {
                $title = (string) $item->getAttribute('document_title');
                $fact = self::excerpt($title, $item->text);
                $cost = self::size($fact) + 1;

                if ($cost <= $left) {
                    $left -= $cost;
                    $documents[] = $fact;
                    $parts[] = ['type' => 'excerpt', 'id' => $item->id, 'document_id' => $item->document_id, 'title' => $title, 'kind' => null, 'text' => $item->text, 'size' => $cost, 'matched' => true, 'pinned' => false];
                }

                continue;
            }

            $fact = self::fact($item);
            $cost = self::size($fact) + 1;

            if ($cost > $room) {
                $fits[$item->id] = PromptFit::TooLong;
            } elseif ($cost > $left) {
                $fits[$item->id] = PromptFit::NoRoom;
            } else {
                $fits[$item->id] = PromptFit::Included;
                $left -= $cost;
                $parts[] = ['type' => 'entry', 'id' => $item->id, 'document_id' => null, 'title' => $item->title, 'kind' => $item->kind->value, 'text' => $item->body, 'size' => $cost, 'matched' => isset($matched[$item->id]), 'pinned' => (bool) $item->pinned];

                if ($item->kind->isExample()) {
                    $examples[] = $fact;
                } else {
                    $facts[] = $fact;
                }
            }
        }

        $sections = array_filter([
            $profileText,
            $facts === [] ? '' : self::ENTRIES_HEADING."\n".implode("\n", $facts),
            $documents === [] ? '' : self::DOCUMENTS_HEADING."\n".implode("\n", $documents),
            $examples === [] ? '' : self::EXAMPLES_HEADING."\n".implode("\n", $examples),
        ]);
        $text = implode("\n\n", $sections);

        return new KnowledgePrompt($text, self::size($text), $budget, $profileTrimmed, $fits, self::size($profileText), $parts);
    }

    /**
     * The order a focused prompt fills in: pinned entries, then the matches
     * (entries, and excerpts up to the limit, each scoring at least a share
     * of the best), then the other entries newest first.
     *
     * @param  Collection<int, KnowledgeEntry>  $entries  In prompt order
     * @return array{0: list<KnowledgeEntry|KnowledgeChunk>, 1: array<int, true>} The queue, and the matched entry ids
     */
    private static function focusedOrder(Workspace $workspace, string $focus, Collection $entries): array
    {
        $matches = app(KnowledgeSearch::class)->search($workspace, $focus);
        $floor = ($matches[0]->score ?? 0.0) * (float) config('socialhq.knowledge.search.min_relevance', 0.25);
        $relevant = array_values(array_filter($matches, fn (KnowledgeMatch $match): bool => $match->score >= $floor));

        $chunkIds = array_slice(array_map(
            fn (KnowledgeMatch $match): int => $match->id,
            array_values(array_filter($relevant, fn (KnowledgeMatch $match): bool => ! $match->isEntry())),
        ), 0, max(0, (int) config('socialhq.knowledge.documents.prompt_excerpts', 6)));

        $chunks = $chunkIds === [] ? collect() : KnowledgeChunk::query()
            ->whereIn('shq_knowledge_chunks.id', $chunkIds)
            ->where('shq_knowledge_chunks.workspace_id', $workspace->id)
            ->join('shq_knowledge_documents', 'shq_knowledge_documents.id', '=', 'shq_knowledge_chunks.document_id')
            ->get(['shq_knowledge_chunks.id', 'shq_knowledge_chunks.document_id', 'shq_knowledge_chunks.text', 'shq_knowledge_documents.title as document_title'])
            ->keyBy('id');

        $byId = $entries->keyBy('id');
        $queue = $entries->filter(fn (KnowledgeEntry $entry): bool => $entry->pinned)->values()->all();
        $matched = [];

        foreach ($relevant as $match) {
            if ($match->isEntry()) {
                $entry = $byId->get($match->id);

                if ($entry !== null && ! $entry->pinned) {
                    $queue[] = $entry;
                    $matched[$entry->id] = true;
                }
            } elseif ($chunks->has($match->id)) {
                $queue[] = $chunks->get($match->id);
            }
        }

        foreach ($entries as $entry) {
            if (! $entry->pinned && ! isset($matched[$entry->id])) {
                $queue[] = $entry;
            }
        }

        return [$queue, $matched];
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

    /**
     * The prompt's room. A focused prompt gets more (prompt_characters_focused):
     * what fills it was picked for the request, so it is worth the tokens.
     */
    public static function budget(bool $focused = false): int
    {
        $budget = max(500, (int) config('socialhq.knowledge.prompt_characters', 6000));

        return $focused ? max($budget, (int) config('socialhq.knowledge.prompt_characters_focused', 8000)) : $budget;
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

    /**
     * A document excerpt as a fact: the document's title, then the text on
     * one indented block (blank lines dropped).
     */
    private static function excerpt(string $documentTitle, string $text): string
    {
        $title = self::clean($documentTitle);
        $body = str_replace("\n", "\n  ", self::clean((string) preg_replace('/\n{2,}/', "\n", $text)));

        return "- {$title}\n  {$body}";
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
