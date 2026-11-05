<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\SocialHq\Ai\AiTask;
use App\SocialHq\Ai\UsesAiTask;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

#[Temperature(0.2)]
class PostContentShortener implements Agent
{
    use Promptable;
    use UsesAiTask;

    public function __construct(
        public string $platformLabel,
        public int $limit,
    ) {}

    /**
     * Social HQ: where AiRouter sends this agent's calls.
     */
    public function aiTask(): AiTask
    {
        return AiTask::Write;
    }

    public function instructions(): string
    {
        return view('prompts.post_content.shortener', [
            'platform_label' => $this->platformLabel,
            'limit' => $this->limit,
            'target' => max(1, (int) floor($this->limit * 0.95)),
        ])->render();
    }
}
