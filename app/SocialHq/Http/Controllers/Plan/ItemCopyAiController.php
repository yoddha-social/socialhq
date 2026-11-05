<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\Plan;

use App\Http\Controllers\Controller;
use App\SocialHq\Ai\AiRouter;
use App\SocialHq\Ai\AiTask;
use App\SocialHq\Plan\Ai\WriteItemCopy;
use App\SocialHq\Plan\PlanAccess;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Ai\Exceptions\AiException;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Write with AI" / "Rewrite with AI": the copy comes back as a new version.
 */
class ItemCopyAiController extends Controller
{
    public function __invoke(Request $request, int $item): JsonResponse
    {
        $planItem = PlanAccess::item($request, $item);

        abort_unless(AiRouter::isAvailable(AiTask::Write), Response::HTTP_NOT_FOUND);

        try {
            $version = WriteItemCopy::execute($planItem, $request->user());
        } catch (AiException|RequestException|ConnectionException) {
            $version = null;
        }

        if ($version === null) {
            return response()->json(['message' => __('socialhq_production.item.copy.ai_failed')], Response::HTTP_BAD_GATEWAY);
        }

        return response()->json(['number' => $version->number], Response::HTTP_CREATED);
    }
}
