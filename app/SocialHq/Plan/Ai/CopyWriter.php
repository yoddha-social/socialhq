<?php

declare(strict_types=1);

namespace App\SocialHq\Plan\Ai;

use App\SocialHq\Ai\AiTask;
use App\SocialHq\Ai\UsesAiTask;
use App\SocialHq\Ai\UsesModelSafeTemperature;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Writes or rewrites the copy of a plan item: caption, hashtags, a script
 * for video, and notes for the team.
 */
#[MaxTokens(3000)]
class CopyWriter implements Agent, HasStructuredOutput
{
    use Promptable;
    use UsesAiTask;
    use UsesModelSafeTemperature;

    /**
     * @param  array<string, mixed>  $context  The item, its event and channel, the latest version and open change requests.
     */
    public function __construct(
        public string $language,
        public array $context,
        public string $brandKnowledge,
        public bool $isVideo,
        public bool $isRewrite,
        public ?int $maxLength,
        public int $maxHashtags,
        public string $visualStyle = '',
    ) {}

    public function aiTask(): AiTask
    {
        return AiTask::Write;
    }

    public function instructions(): string
    {
        return view('prompts.socialhq.copy_writer', [
            'language' => $this->language,
            'context' => json_encode($this->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'brand_knowledge' => $this->brandKnowledge,
            'is_video' => $this->isVideo,
            'is_rewrite' => $this->isRewrite,
            'max_length' => $this->maxLength,
            'max_hashtags' => $this->maxHashtags,
            'visual_style' => $this->visualStyle,
        ])->render();
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            // Every key is required (strict providers such as Groq refuse a schema that leaves
            // one out, and models drop optional keys); "" means none.
            'caption' => $schema->string()->required(),
            'hashtags' => $schema->string()->description('Space-separated hashtags, or "".')->required(),
            'script' => $schema->string()->description('The video script, or "" when the creative is not a video.')->required(),
            'notes' => $schema->string()->description('Notes for the reviewer, or "".')->required(),
        ];
    }

    protected function preferredTemperature(): float
    {
        return 0.7;
    }
}
