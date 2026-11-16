<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\SocialHq\Ai\Usage\AiUsagePage;
use App\SocialHq\Companies\PlatformAdmins;
use App\SocialHq\Http\Requests\ShowPlatformAiUsageRequest;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Platform admins: every company's AI calls, filterable by company. Anyone
 * else gets a 404, like the other platform pages.
 */
class AiUsageController extends Controller
{
    public function __invoke(ShowPlatformAiUsageRequest $request): Response
    {
        if (Gate::forUser($request->user())->denies(PlatformAdmins::GATE)) {
            throw new NotFoundHttpException;
        }

        return Inertia::render('socialhq/platform/AiUsage', AiUsagePage::props(null, $request->validated()));
    }
}
