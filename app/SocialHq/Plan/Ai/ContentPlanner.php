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
            // Every key is required and none is nullable: providers with strict structured
            // output (Groq) refuse a schema that leaves a key out of `required`, and some
            // models drop a nullable key altogether. An empty string means "none"; the job
            // reads it (or a missing key) as null.
            'items' => $schema->array()->max(self::MAX_ITEMS)->items($schema->object([
                'date' => $schema->string()->required(),
                'event_key' => $schema->string()->description('The event\'s key, or "" when the post is not about an event.')->required(),
                'channel' => $schema->string()->required(),
                'content_type' => $schema->string()->required(),
                'creative' => $schema->string()->enum(array_map(fn (CreativeKind $kind): string => $kind->value, CreativeKind::cases()))->required(),
                'title' => $schema->string()->required(),
                'pillar' => $schema->string()->description('One of the pillars, or "" when there are none.')->required(),
                'objective' => $schema->string()->required(),
                'angle' => $schema->string()->required(),
                'suggested_time' => $schema->string()->description('Posting time "HH:MM" (24-hour) in the audience\'s main_timezone, or "" when unsure.')->required(),
            ])->withoutAdditionalProperties())->required(),
        ];
    }

    protected function preferredTemperature(): float
    {
        return 0.6;
    }
}
