<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\SocialHq\Ai\Usage\AiUsagePage;
use App\SocialHq\Companies\PlatformAdmins;
use App\SocialHq\Http\Requests\ShowPlatformAiUsageDayRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The rows of one day on the platform admins' AI usage page. Anyone else
 * gets a 404, like the page.
 */
class AiUsageDayController extends Controller
{
    public function __invoke(ShowPlatformAiUsageDayRequest $request): JsonResponse
    {
        if (Gate::forUser($request->user())->denies(PlatformAdmins::GATE)) {
            throw new NotFoundHttpException;
        }

        return response()->json(AiUsagePage::dayRows(null, $request->validated(), $request->user()));
    }
}
