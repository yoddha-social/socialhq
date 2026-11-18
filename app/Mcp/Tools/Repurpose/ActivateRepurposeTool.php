<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Repurpose;

use App\Actions\Repurpose\ActivateRepurpose;
use App\Http\Resources\Api\RepurposeResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Mcp\Concerns\ResolvesWorkspaceRepurpose;
use App\Mcp\Requests\Repurpose\RepurposeIdRequest;
use App\Models\Repurpose;
use App\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
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
#[IsOpenWorld(true)]
#[Description('Activate a repurpose. From then on each new video on the source account becomes a post on every destination. With publish_mode publish, those posts are scheduled at once as the person who created the repurpose, with no approval step when that person publishes directly; with draft they wait in Social HQ. Requires at least one destination and stamps the watermark, so only videos published from now on are replicated. An API key or MCP connection whose posts need approval cannot activate one.')]
class ActivateRepurposeTool extends Tool
{
    use AuthorizesMcpTool, ResolvesWorkspaceRepurpose;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->currentWorkspace($request);

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $validated = $request->validate(RepurposeIdRequest::rules());
        $repurpose = $this->repurposeInWorkspace($workspace, $validated['repurpose_id']);

        if (! $repurpose instanceof Repurpose) {
            return $repurpose;
        }

        if ($denied = $this->denyUnlessCan($request, 'update', $repurpose, 'Repurpose not found.')) {
            return $denied;
        }

        try {
            $repurpose = ActivateRepurpose::execute($repurpose);
        } catch (ValidationException $e) {
            return Response::error($e->getMessage());
        }

        return Response::structured((new RepurposeResource($repurpose))->resolve());
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'repurpose_id' => $schema->string()->required()->description('The repurpose to activate.'),
        ];
    }
}
