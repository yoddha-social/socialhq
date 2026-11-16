<?php

declare(strict_types=1);

namespace App\SocialHq\Ai;

use Laravel\Ai\Enums\Lab;

/**
 * For agents: the SDK calls provider() when a prompt names no provider, and
 * an array answer is its failover list. The agent says what it is for in
 * aiTask(); AiRouter decides where that goes.
 */
trait UsesAiTask
{
    /**
     * The route provider() last returned. The SDK asks provider() before the
     * request options, so temperature() reuses it instead of routing again.
     *
     * @var array<string, string|null>|null
     */
    private ?array $aiRoute = null;

    abstract public function aiTask(): AiTask;

    /**
     * @return array<string, string|null>
     */
    public function provider(): array
    {
        return $this->aiRoute = AiRouter::route($this->aiTask());
    }

    /**
     * The route of the prompt being built, when provider() gave one.
     *
     * @return array<string, string|null>|null
     */
    public function aiRoute(): ?array
    {
        return $this->aiRoute;
    }

    /**
     * Extra request fields: on gateway calls, who the call is for
     * (AiRouter::gatewayAttribution). The agent implements
     * Laravel\Ai\Contracts\HasProviderOptions for the SDK to ask.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        return AiRouter::gatewayAttribution($this->aiTask(), $provider);
    }

    /**
     * Seconds the SDK waits for the answer (AiTimeout). A timeout passed to
     * prompt() still wins.
     */
    public function timeout(): int
    {
        return $this->runsQueued() ? AiTimeout::QUEUED : AiTimeout::WEB;
    }

    /**
     * Whether the agent only runs in queued jobs, where no browser waits.
     */
    protected function runsQueued(): bool
    {
        return false;
    }
}
