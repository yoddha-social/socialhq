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
     * @param  array<int, PromptFit>  $fits  Every entry marked for AI, by id
     */
    public function __construct(
        public string $text,
        public int $used,
        public int $budget,
        public bool $profileTrimmed,
        private array $fits,
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

    public function fitOf(KnowledgeEntry $entry): PromptFit
    {
        return $entry->use_in_ai ? ($this->fits[$entry->id] ?? PromptFit::NoRoom) : PromptFit::Off;
    }
}
