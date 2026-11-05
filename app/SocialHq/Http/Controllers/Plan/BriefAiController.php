<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\Plan;

use App\Http\Controllers\Controller;
use App\SocialHq\Ai\AiRouter;
use App\SocialHq\Ai\AiTask;
use App\SocialHq\Plan\PlanAccess;
use App\SocialHq\Production\Ai\DraftBrief;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Ai\Exceptions\AiException;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Draft brief with AI": returns a draft for the page to fill in; saving
 * stays with the person.
 */
class BriefAiController extends Controller
{
    public function __invoke(Request $request, int $item): JsonResponse
    {
        $brief = PlanAccess::item($request, $item)->brief ?? abort(Response::HTTP_NOT_FOUND);

        abort_unless(AiRouter::isAvailable(AiTask::Write), Response::HTTP_NOT_FOUND);

        try {
            $draft = DraftBrief::execute($brief, $request->user());
        } catch (AiException|RequestException|ConnectionException) {
            $draft = null;
        }

        if ($draft === null) {
            return response()->json(['message' => __('socialhq_production.brief.ai_failed')], Response::HTTP_BAD_GATEWAY);
        }

        return response()->json($draft);
    }
}
