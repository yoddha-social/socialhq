<?php

declare(strict_types=1);

namespace App\SocialHq\Mcp\Tools\Plan;

use App\Models\Workspace;
use App\SocialHq\Mcp\Concerns\WorksOnPlans;
use App\SocialHq\Plan\PlanItem;
use App\SocialHq\Plan\PlanMonth;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(true)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
#[Description('Read the content plan of one month for the current workspace (one brand): the plan\'s status (draft, in_review or approved; a person approves it in the app), its goals and its items, in date order. An item is one planned piece of content: scheduled_on (a calendar day), channel, content_type, creative (image, video, ...), title (what to make), pillar, objective, angle, event, owner, status (suggested by AI and not accepted yet, planned, or dropped) and copy_status (none, draft, in_review, changes_requested or approved). suggested_at is the suggested posting instant in UTC. plan is null when nobody started the month. Use get-plan-item-tool for an item\'s copy and review thread, and list-plan-events-tool for the month\'s dated events.')]
class GetContentPlanTool extends Tool
{
    use WorksOnPlans;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->planWorkspace($request);

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $month = $this->planMonth($request);
        $plan = PlanMonth::find($workspace, $month);

        return Response::structured([
            'month' => PlanMonth::key($month),
            'plan' => $plan === null ? null : [
                'id' => $plan->id,
                'status' => $plan->status->value,
                'goals' => $plan->goals,
                'submitted_at' => $plan->submitted_at?->toIso8601ZuluString(),
                'approved_at' => $plan->approved_at?->toIso8601ZuluString(),
            ],
            'items' => $plan === null ? [] : $plan->items()
                ->with(['plan', 'owner', 'event', 'socialAccount', 'latestVersion.author'])
                ->orderBy('scheduled_on')
                ->orderBy('position')
                ->orderBy('id')
                ->get()
                ->map(fn (PlanItem $item): array => $this->itemPayload($item))
                ->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'month' => $schema->string()->description('The month as YYYY-MM, e.g. 2026-12. Omitted, the current month in the user\'s time zone.'),
        ];
    }
}
