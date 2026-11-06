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
use Throwable;

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
        } catch (Throwable $exception) {
            // A reply the SDK can't parse (or any other failure of the call) is a failed draft, not a 500.
            report($exception);
            $version = null;
        }

        if ($version === null) {
            return response()->json(['message' => __('socialhq_production.item.copy.ai_failed')], Response::HTTP_BAD_GATEWAY);
        }

        return response()->json(['number' => $version->number], Response::HTTP_CREATED);
    }
}
