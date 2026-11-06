<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Website;

use App\SocialHq\Ai\AiTask;
use App\SocialHq\Ai\UsesAiTask;
use App\SocialHq\Ai\UsesModelSafeTemperature;
use App\SocialHq\Knowledge\KnowledgeKind;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Drafts brand knowledge from the cleaned text of a brand's website:
 * profile fields, knowledge entries and theme hints. Only a proposal:
 * WebsiteSuggestions checks it, and an admin accepts each part.
 */
#[MaxTokens(4000)]
class WebsiteReader implements Agent, HasStructuredOutput
{
    use Promptable;
    use UsesAiTask;
    use UsesModelSafeTemperature;

    public const MAX_ENTRIES = 15;

    /** The kinds the AI may suggest: example posts are the team's own. */
    public const KINDS = [KnowledgeKind::Product, KnowledgeKind::Project, KnowledgeKind::Campaign, KnowledgeKind::Fact, KnowledgeKind::Other];

    public function __construct(public string $language) {}

    public function aiTask(): AiTask
    {
        return AiTask::Structured;
    }

    public function instructions(): string
    {
        return view('prompts.socialhq.website_reader', [
            'language' => $this->language,
            'max_entries' => self::MAX_ENTRIES,
        ])->render();
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        // Every key is required and none is nullable (strict providers such as Groq
        // refuse a schema that leaves one out); "" or [] means none.
        return [
            'profile' => $schema->object([
                'about' => $schema->string()->required(),
                'audience' => $schema->string()->required(),
                'voice' => $schema->string()->required(),
            ])->withoutAdditionalProperties()->required(),
            'entries' => $schema->array()->max(self::MAX_ENTRIES)->items($schema->object([
                'kind' => $schema->string()->enum(array_map(fn (KnowledgeKind $kind): string => $kind->value, self::KINDS))->required(),
                'title' => $schema->string()->required(),
                'body' => $schema->string()->required(),
            ])->withoutAdditionalProperties())->required(),
            'theme' => $schema->object([
                'colours' => $schema->array()->max(8)->items($schema->object([
                    'name' => $schema->string()->required(),
                    'hex' => $schema->string()->required(),
                ])->withoutAdditionalProperties())->required(),
                'heading_font' => $schema->string()->required(),
                'body_font' => $schema->string()->required(),
                'style_notes' => $schema->string()->required(),
            ])->withoutAdditionalProperties()->required(),
        ];
    }

    protected function preferredTemperature(): float
    {
        return 0.3;
    }
}
