<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\Plan;

use App\Http\Controllers\Controller;
use App\SocialHq\Ai\AiResponses;
use App\SocialHq\Ai\AiTask;
use App\SocialHq\Http\Requests\Plan\WriteItemCopyRequest;
use App\SocialHq\Knowledge\Keywords\BrandKeywords;
use App\SocialHq\Plan\Ai\WriteItemCopy;
use App\SocialHq\Plan\PlanAccess;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * "Write with AI" / "Rewrite with AI", with the team's optional request:
 * the copy comes back as a new version.
 */
class ItemCopyAiController extends Controller
{
    public function __invoke(WriteItemCopyRequest $request, int $item): JsonResponse
    {
        $planItem = PlanAccess::item($request, $item);

        if (($refusal = AiResponses::refusal(AiTask::Write, $request->user(), $planItem->workspace)) !== null) {
            return $refusal;
        }

        try {
            $version = WriteItemCopy::execute($planItem, $request->user(), $request->validated('instruction'));
        } catch (Throwable $exception) {
            // A reply the SDK can't parse (or any other failure of the call) is a failed draft, not a 500.
            return AiResponses::failed($exception, __('socialhq_production.item.copy.ai_failed'));
        }

        if ($version === null) {
            return response()->json(['message' => __('socialhq_production.item.copy.ai_failed')], Response::HTTP_BAD_GATEWAY);
        }

        // keywords_missing: the brand has no SEO keywords, so the page shows a light note under the copy.
        return response()->json([
            'number' => $version->number,
            'keywords_missing' => BrandKeywords::isEmpty($planItem->workspace),
        ], Response::HTTP_CREATED);
    }
}
