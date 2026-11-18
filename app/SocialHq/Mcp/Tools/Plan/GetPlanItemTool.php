<?php

declare(strict_types=1);

namespace App\SocialHq\Mcp\Tools\Plan;

use App\Models\Workspace;
use App\SocialHq\Mcp\Concerns\WorksOnPlans;
use App\SocialHq\Plan\ItemCopy;
use App\SocialHq\Plan\ItemPage;
use App\SocialHq\Plan\ItemReview;
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

#[IsReadOnly(true)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
#[Description('Read one content plan item: what to make, its channel and format, the event it is for, its latest copy (caption, hashtags, script for video, notes; versions are numbered and never edited in place), copy_status, open_change_requests (what reviewers asked to change in the latest version, to address in the next one), the review thread (sent for review, changes requested, approved, reopened, comments) and the creative brief when there is one. max_length is the longest caption the channel takes.')]
class GetPlanItemTool extends Tool
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

        $item->load(['reviews.user', 'reviews.version', 'brief']);
        $brief = $item->brief;

        return Response::structured([
            ...$this->itemPayload($item),
            'max_length' => ItemPage::maxLength($item),
            'open_change_requests' => ItemCopy::openChangeRequests($item),
            'thread' => $item->reviews->map(fn (ItemReview $review): array => $this->reviewPayload($review))->values()->all(),
            'brief' => $brief === null ? null : [
                'kind' => $brief->kind->value,
                'stage' => $brief->stage->value,
                'due_on' => $brief->due_on?->toDateString(),
                'format_notes' => $brief->format_notes,
                'on_screen_text' => $brief->on_screen_text,
                'direction' => $brief->direction,
                'references' => $brief->references,
            ],
        ]);
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
