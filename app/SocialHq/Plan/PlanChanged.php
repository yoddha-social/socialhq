<?php

declare(strict_types=1);

namespace App\SocialHq\Plan;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tells the brand's open plan pages that a month changed (an item, its copy
 * or brief, or the plan itself), so they reload it (QA 2026-11-18, item 8).
 * It carries the month only; the page asks the server for the rest.
 */
class PlanChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly string $workspaceId,
        public readonly string $month,
    ) {}

    /**
     * One broadcast per plan for everything a request or job changed, sent
     * once it has finished.
     */
    public static function dispatchFor(?int $planId): void
    {
        if ($planId === null) {
            return;
        }

        defer(function () use ($planId): void {
            $plan = ContentPlan::query()->find($planId, ['id', 'workspace_id', 'month']);

            if ($plan !== null) {
                broadcast(new self($plan->workspace_id, PlanMonth::key($plan->month->toImmutable())))->toOthers();
            }
        }, "socialhq-plan-changed-{$planId}");
    }

    public function broadcastAs(): string
    {
        return 'shq.plan.changed';
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("workspace.{$this->workspaceId}")];
    }

    /**
     * @return array{month: string}
     */
    public function broadcastWith(): array
    {
        return ['month' => $this->month];
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }
}
