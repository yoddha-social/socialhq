<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Ai\AssistPostContent;
use App\Enums\Ai\PostAssistantMode;
use App\Enums\PostHog\AiEvent;
use App\Enums\SocialAccount\Platform;
use App\Http\Requests\App\Ai\AssistPostContentRequest;
use App\Jobs\PostHog\CaptureAccountEvent;
use App\Services\PostHogService;
use App\SocialHq\Access\AiAccess;
use App\SocialHq\Ai\AiResponses;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Laravel\Ai\Exceptions\AiException;

class PostAiAssistantController extends Controller
{
    public function __invoke(AssistPostContentRequest $request): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace;

        $this->authorize('createPost', $workspace);

        // Social HQ: the role's "Use AI" too (AiAccess).
        if (($refusal = AiAccess::refusal($request->user(), $workspace)) !== null) {
            return $refusal;
        }

        $mode = PostAssistantMode::from($request->string('mode')->toString());

        try {
            $content = AssistPostContent::execute(
                workspace: $workspace,
                user: $request->user(),
                mode: $mode,
                currentContent: $request->string('current_content')->toString(),
                prompt: $request->input('prompt'),
                previousContent: $request->input('previous_content'),
                platform: $request->enum('platform', Platform::class),
                account: $request->filled('social_account_id')
                    ? $workspace->socialAccounts()->find($request->input('social_account_id'))
                    : null,
            );
        } catch (AiException|RequestException|ConnectionException $exception) {
            // Social HQ: AI not set up for the company is a 409 with how to set it up; other failures are reported.
            return AiResponses::failed($exception, __('posts.composer.assistant_error'));
        }

        rescue(function () use ($request, $workspace, $mode): void {
            CaptureAccountEvent::dispatchIf(
                PostHogService::isEnabled(),
                (string) $request->user()->id,
                (string) $workspace->account_id,
                AiEvent::AssistUsed,
                [
                    'mode' => $mode->value,
                    'workspace_id' => (string) $workspace->id,
                ],
            );
        });

        return response()->json(['content' => $content]);
    }
}
