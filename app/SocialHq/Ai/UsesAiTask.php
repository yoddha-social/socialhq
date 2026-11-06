<?php

declare(strict_types=1);

namespace App\SocialHq\Ai;

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
        return AiRouter::route($this->aiTask());
    }
}
