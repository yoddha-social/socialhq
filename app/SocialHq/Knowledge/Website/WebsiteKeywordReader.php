<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Website;

use App\SocialHq\Ai\AiTask;
use App\SocialHq\Ai\UsesAiTask;
use App\SocialHq\Ai\UsesModelSafeTemperature;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * "Read our website", second step: SEO keywords from what the pages
 * already read stress (WebsiteKeywordSignals), pre-tagged with the brand's
 * pillars, products and occasions. Only a proposal: WebsiteKeywordSuggestions
 * checks it, and an admin accepts each one.
 */
#[MaxTokens(3000)]
class WebsiteKeywordReader implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;
    use UsesAiTask;
    use UsesModelSafeTemperature;

    /** At most this many keywords: up to 5 main and 15 more. */
    public const int MAX_KEYWORDS = 20;

    /** The review groups a keyword is shown under. */
    public const array GROUPS = ['features', 'problems', 'audience', 'place', 'seasonal'];

    public function __construct(public string $language, public int $mainSlots) {}

    public function aiTask(): AiTask
    {
        return AiTask::Structured;
    }

    /**
     * Only ReadWebsite (queued) prompts it: it gets AiTimeout::QUEUED.
     */
    protected function runsQueued(): bool
    {
        return true;
    }

    public function instructions(): string
    {
        return view('prompts.socialhq.website_keywords', [
            'language' => $this->language,
            'max_keywords' => self::MAX_KEYWORDS,
            'main_slots' => $this->mainSlots,
        ])->render();
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        // Every key is required and none is nullable (strict providers refuse optional keys); "" or 0 means none.
        return [
            'keywords' => $schema->array()->max(self::MAX_KEYWORDS)->items($schema->object([
                'text' => $schema->string()->required(),
                'main' => $schema->boolean()->required(),
                'group' => $schema->string()->enum(self::GROUPS)->required(),
                'source' => $schema->string()->required(),
                'pillar' => $schema->string()->required(),
                'product' => $schema->string()->required(),
                'occasion' => $schema->string()->required(),
                'months_from' => $schema->integer()->min(0)->max(12)->required(),
                'months_to' => $schema->integer()->min(0)->max(12)->required(),
                'sample' => $schema->string()->required(),
            ])->withoutAdditionalProperties())->required(),
        ];
    }

    protected function preferredTemperature(): float
    {
        return 0.3;
    }
}
