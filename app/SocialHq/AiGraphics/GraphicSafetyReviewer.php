<?php

declare(strict_types=1);

namespace App\SocialHq\AiGraphics;

use App\SocialHq\Ai\AiTask;
use App\SocialHq\Ai\UsesAiTask;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * The vision check of AI graphics: looks at a request (prompt and reference
 * images) or at a generated graphic and says whether a company may post it.
 */
class GraphicSafetyReviewer implements Agent, HasStructuredOutput
{
    use Promptable;
    use UsesAiTask;

    public function __construct(public bool $reviewsResult = false) {}

    public function aiTask(): AiTask
    {
        return AiTask::Vision;
    }

    public function instructions(): string
    {
        return view('prompts.socialhq.graphic_safety_review', ['reviews_result' => $this->reviewsResult])->render();
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'allowed' => $schema->boolean()->required(),
            'reason' => $schema->string()->required(),
        ];
    }
}
