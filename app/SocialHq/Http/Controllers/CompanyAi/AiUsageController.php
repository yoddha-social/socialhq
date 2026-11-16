<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\CompanyAi;

use App\Http\Controllers\Controller;
use App\SocialHq\Ai\Company\CompanyAiAccess;
use App\SocialHq\Ai\Usage\AiUsagePage;
use App\SocialHq\Http\Requests\CompanyAi\ShowAiUsageRequest;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Settings › AI › Usage: the company's AI calls and how each was routed.
 * Only the owner of an approved company sees it, like Settings › AI.
 */
class AiUsageController extends Controller
{
    public function __invoke(ShowAiUsageRequest $request): Response
    {
        $account = CompanyAiAccess::accountFor($request->user());

        abort_if($account === null, HttpResponse::HTTP_FORBIDDEN);

        return Inertia::render('socialhq/settings/AiUsage', AiUsagePage::props($account, $request->validated()));
    }
}
