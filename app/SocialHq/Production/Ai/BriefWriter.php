<?php

declare(strict_types=1);

namespace App\SocialHq\Production\Ai;

use App\SocialHq\Ai\AiTask;
use App\SocialHq\Ai\UsesAiTask;
use App\SocialHq\Ai\UsesModelSafeTemperature;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Drafts a creative brief from an item's copy and format: the text on the
 * graphic or video, the art direction and ideas for references.
 */
#[MaxTokens(2000)]
class BriefWriter implements Agent, HasStructuredOutput
{
    use Promptable;
    use UsesAiTask;
    use UsesModelSafeTemperature;

    /**
     * @param  array<string, mixed>  $context  The item, its copy, the brief's kind and format.
     */
    public function __construct(
        public string $language,
        public array $context,
        public string $brandKnowledge,
        public bool $isVideo,
    ) {}

    public function aiTask(): AiTask
    {
        return AiTask::Write;
    }

    public function instructions(): string
    {
        return view('prompts.socialhq.brief_writer', [
            'language' => $this->language,
            'context' => json_encode($this->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'brand_knowledge' => $this->brandKnowledge,
            'is_video' => $this->isVideo,
        ])->render();
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            // Every key is required (strict providers refuse a schema that leaves one out); "" means none.
            'on_screen_text' => $schema->string()->description('The words on the creative, one line each, or "".')->required(),
            'direction' => $schema->string()->required(),
            'references' => $schema->string()->description('Reference ideas, one per line, or "".')->required(),
        ];
    }

    protected function preferredTemperature(): float
    {
        return 0.6;
    }
}
