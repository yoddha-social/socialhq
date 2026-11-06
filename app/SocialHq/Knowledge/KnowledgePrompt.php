<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge;

/**
 * The brand knowledge one AI prompt gets (BrandKnowledge::assemble()), with
 * what made it in, for the page that shows it.
 */
final readonly class KnowledgePrompt
{
    /**
     * $fits holds every entry marked for AI, by id; $profileUsed is the space
     * the profile takes; $parts are the entries and excerpts in the prompt,
     * in the order they were picked.
     *
     * @param  array<int, PromptFit>  $fits
     * @param  list<array{type: 'entry'|'excerpt', id: int, document_id: int|null, title: string, kind: string|null, text: string, size: int, matched: bool, pinned: bool}>  $parts
     */
    public function __construct(
        public string $text,
        public int $used,
        public int $budget,
        public bool $profileTrimmed,
        private array $fits,
        public int $profileUsed = 0,
        public array $parts = [],
    ) {}

    /**
     * Ids of the entries in the prompt, in prompt order.
     *
     * @return list<int>
     */
    public function entryIds(): array
    {
        return array_keys(array_filter($this->fits, fn (PromptFit $fit): bool => $fit === PromptFit::Included));
    }

    /**
     * Ids of the document excerpts in the prompt, best match first.
     *
     * @return list<int>
     */
    public function chunkIds(): array
    {
        return array_values(array_map(
            fn (array $part): int => $part['id'],
            array_filter($this->parts, fn (array $part): bool => $part['type'] === 'excerpt'),
        ));
    }

    public function fitOf(KnowledgeEntry $entry): PromptFit
    {
        return $entry->use_in_ai ? ($this->fits[$entry->id] ?? PromptFit::NoRoom) : PromptFit::Off;
    }
}
