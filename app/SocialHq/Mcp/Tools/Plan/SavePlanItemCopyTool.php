<?php

declare(strict_types=1);

namespace App\SocialHq\Mcp\Tools\Plan;

use App\Models\Workspace;
use App\SocialHq\Knowledge\BrandKnowledge;
use App\SocialHq\Mcp\Concerns\WorksOnPlans;
use App\SocialHq\Plan\Ai\UnsupportedDetails;
use App\SocialHq\Plan\Ai\WriteItemCopy;
use App\SocialHq\Plan\CopyStatus;
use App\SocialHq\Plan\ItemCopy;
use App\SocialHq\Plan\ItemSource;
use App\SocialHq\Plan\ItemVersion;
use App\SocialHq\Plan\PlanItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
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
#[Description('Save new copy for a content plan item as its next numbered version (earlier versions stay), marked as written by AI. The copy goes to draft, and an AI suggestion it belongs to joins the plan. Nothing is posted: copy becomes a post only after a person approves it and creates the post in the app. Refused while the copy is in review or approved, because a new version would undo that review; a person reopens it in the app. Read the item first (get-plan-item-tool) and address its open_change_requests; read get-brand-knowledge-tool for the brand\'s voice. Send it for review with submit-plan-item-copy-tool.')]
class SavePlanItemCopyTool extends Tool
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

        $copy = $request->validate([
            'caption' => ['required', 'string', 'max:25000'],
            'hashtags' => ['nullable', 'string', 'max:500'],
            'script' => ['nullable', 'string', 'max:20000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        // What the agent wrote that the item and the brand knowledge don't say, for the reviewer to check.
        $item->loadMissing(['event', 'socialAccount', 'latestVersion']);
        $copy['unsupported_details'] = UnsupportedDetails::find(
            trim($copy['caption']."\n\n".($copy['script'] ?? '')),
            WriteItemCopy::sources($item, WriteItemCopy::context($item), BrandKnowledge::forPrompt($workspace, WriteItemCopy::focus($item)), ''),
        ) ?: null;

        // Checked under the item's lock, so a review that starts meanwhile is never undone.
        $version = DB::transaction(function () use ($item, $request, $copy): ?ItemVersion {
            $locked = PlanItem::query()->lockForUpdate()->findOrFail($item->id);

            if (in_array($locked->copy_status, [CopyStatus::InReview, CopyStatus::Approved], true)) {
                return null;
            }

            return ItemCopy::saveVersion($item, $request->user(), $copy, ItemSource::Ai);
        });

        if ($version === null) {
            return Response::error(__('socialhq.agents.copy_under_review'));
        }

        return Response::structured($this->itemPayload($item->refresh()));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'item_id' => $schema->integer()->required()->description('The item\'s id, from get-content-plan-tool.'),
            'caption' => $schema->string()->required()->description('The post text, in plain text; use \n for line breaks. Keep it within the item\'s max_length (get-plan-item-tool).'),
            'hashtags' => $schema->string()->description('Optional hashtags, space separated, up to 500 characters.'),
            'script' => $schema->string()->description('Optional script, for a video item, up to 20000 characters.'),
            'notes' => $schema->string()->description('Optional notes for the team, up to 5000 characters.'),
        ];
    }
}
