<?php

declare(strict_types=1);

namespace App\SocialHq\Mcp\Tools\Plan;

use App\Models\Workspace;
use App\SocialHq\Mcp\Concerns\WorksOnPlans;
use App\SocialHq\Plan\ItemCopy;
use App\SocialHq\Plan\PlanItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsDestructive(true)]
#[IsOpenWorld(false)]
#[Description('Send a content plan item\'s latest copy version for review: copy_status goes from draft or changes_requested to in_review, and the review thread records it. A person who approves posts then approves it or asks for changes in the app; this connection cannot approve. Nothing is posted.')]
class SubmitPlanItemCopyTool extends Tool
{
    use WorksOnPlans;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->planWorkspace($request);

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $item = $this->planItem($request, $workspace);

        if (! $item instanceof PlanItem) {
            return $item;
        }

        ItemCopy::submit($item, $request->user());

        return Response::structured($this->itemPayload($item->refresh()));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'item_id' => $schema->integer()->required()->description('The item\'s id, from get-content-plan-tool.'),
        ];
    }
}
