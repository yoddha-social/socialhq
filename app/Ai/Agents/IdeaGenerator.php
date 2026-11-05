<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Enums\User\Locale;
use App\SocialHq\Ai\AiTask;
use App\SocialHq\Ai\UsesAiTask;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

#[Temperature(0.8)]
class IdeaGenerator implements Agent, HasStructuredOutput
{
    use Promptable;
    use UsesAiTask;

    public function __construct(
        public Locale $locale,
        public string $business,
        public string $audience,
        public ?string $notes = null,
    ) {}

    /**
     * Social HQ: where AiRouter sends this agent's calls.
     */
    public function aiTask(): AiTask
    {
        return AiTask::Structured;
    }

    public function instructions(): string
    {
        return view('prompts.ideas.generator', [
            'business' => $this->business,
            'audience' => $this->audience,
            'notes' => $this->notes,
            'language' => $this->locale->promptLanguage(),
        ])->render();
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required(),
            'body' => $schema->string()->required(),
        ];
    }
}
