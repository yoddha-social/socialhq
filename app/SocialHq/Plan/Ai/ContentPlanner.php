<?php

declare(strict_types=1);

namespace App\SocialHq\Plan\Ai;

use App\Enums\User\Locale;
use App\SocialHq\Ai\AiTask;
use App\SocialHq\Ai\UsesAiTask;
use App\SocialHq\Ai\UsesModelSafeTemperature;
use App\SocialHq\Plan\CreativeKind;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Drafts a month of planned items from the PlannerContext JSON. What it
 * returns is only a proposal: GeneratePlanSuggestions checks every item
 * before saving it as a suggestion.
 *
 * MaxTokens: room for 80 items (some providers default to a few hundred
 * output tokens and stop mid-answer).
 */
#[MaxTokens(ContentPlanner::MAX_OUTPUT_TOKENS)]
class ContentPlanner implements Agent, HasStructuredOutput
{
    use Promptable;
    use UsesAiTask;
    use UsesModelSafeTemperature;

    /** Hard cap on items per run; anything past it is dropped. */
    public const MAX_ITEMS = 80;

    public const MAX_OUTPUT_TOKENS = 12000;

    public function __construct(public Locale $locale) {}

    public function aiTask(): AiTask
    {
        return AiTask::Structured;
    }

    public function instructions(): string
    {
        return view('prompts.socialhq.content_planner', [
            'language' => $this->locale->promptLanguage(),
            'max_items' => self::MAX_ITEMS,
            'event_lead_days' => GeneratePlanSuggestions::EVENT_LEAD_DAYS,
        ])->render();
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()->required(),
            // Only what an item can't do without is required: a model may leave out pillar,
            // event_key, objective and angle (some providers refuse an answer that omits a
            // required key, even a nullable one); the job reads a missing one as null.
            'items' => $schema->array()->max(self::MAX_ITEMS)->items($schema->object([
                'date' => $schema->string()->required(),
                'channel' => $schema->string()->required(),
                'content_type' => $schema->string()->required(),
                'creative' => $schema->string()->enum(array_map(fn (CreativeKind $kind): string => $kind->value, CreativeKind::cases()))->required(),
                'title' => $schema->string()->required(),
                'pillar' => $schema->string()->nullable(),
                'objective' => $schema->string()->nullable(),
                'angle' => $schema->string()->nullable(),
                'event_key' => $schema->string()->nullable(),
            ])->withoutAdditionalProperties())->required(),
        ];
    }

    protected function preferredTemperature(): float
    {
        return 0.6;
    }
}
