<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Idea\GenerateIdea;
use App\Http\Requests\App\Idea\GenerateIdeaRequest;
use App\Http\Resources\App\GeneratedIdeaResource;
use App\SocialHq\Ai\AiResponses;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Laravel\Ai\Exceptions\AiException;
use Symfony\Component\HttpFoundation\Response;

class IdeaGenerateController extends Controller
{
    public function __invoke(GenerateIdeaRequest $request): GeneratedIdeaResource|JsonResponse
    {
        $gate = Gate::inspect('useAi', $request->user()->currentWorkspace->account);
        if ($gate->denied()) {
            return response()->json(['message' => $gate->message()], Response::HTTP_PAYMENT_REQUIRED);
        }

        try {
            $idea = GenerateIdea::execute(
                user: $request->user(),
                business: $request->validated('business'),
                audience: $request->validated('audience'),
                notes: $request->validated('notes'),
            );
        } catch (AiException|RequestException|ConnectionException $exception) {
            // Social HQ: AI not set up for the company is a 409 with how to set it up; other failures are reported.
            return AiResponses::failed($exception, __('create.ideas.errors.generate_failed'));
        }

        if ($idea === null) {
            return response()->json(['message' => __('create.ideas.errors.generate_failed')], Response::HTTP_BAD_GATEWAY);
        }

        return new GeneratedIdeaResource($idea);
    }
}
