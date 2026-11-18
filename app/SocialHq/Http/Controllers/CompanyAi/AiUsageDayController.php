<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\CompanyAi;

use App\Http\Controllers\Controller;
use App\SocialHq\Ai\Company\CompanyAiAccess;
use App\SocialHq\Ai\Usage\AiUsagePage;
use App\SocialHq\Http\Requests\CompanyAi\ShowAiUsageDayRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The rows of one day on Settings › AI › Usage, loaded when the day is
 * opened. Same access as the page: the owner of an approved company.
 */
class AiUsageDayController extends Controller
{
    public function __invoke(ShowAiUsageDayRequest $request): JsonResponse
    {
        $account = CompanyAiAccess::accountFor($request->user());

        abort_if($account === null, HttpResponse::HTTP_FORBIDDEN);

        return response()->json(AiUsagePage::dayRows($account, $request->validated(), $request->user()));
    }
}
