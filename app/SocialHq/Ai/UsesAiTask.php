<?php

declare(strict_types=1);

namespace App\SocialHq\Ai;

use App\SocialHq\Ai\Usage\AiUsageRecorder;

/**
 * For agents: the SDK calls provider() when a prompt names no provider, and
 * an array answer is its failover list. The agent says what it is for in
 * aiTask(); AiRouter decides where that goes.
 */
trait UsesAiTask
{
    abstract public function aiTask(): AiTask;

    /**
     * @return array<string, string|null>
     */
    public function provider(): array
    {
        try {
            return AiRouter::route($this->aiTask());
        } catch (AiUnavailableException $exception) {
            // Settings › AI › Usage lists calls refused before anything was sent.
            app(AiUsageRecorder::class)->blocked($this, $exception);

            throw $exception;
        }
    }
}
