<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\CompanyAi;

use App\Http\Controllers\Controller;
use App\SocialHq\Ai\Company\CompanyAiAccess;
use App\SocialHq\Ai\Company\CompanyAiPage;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Settings › AI: the company's own AI providers, their order and budget.
 * Only the owner of an approved company sees it.
 */
class CompanyAiSettingsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $account = CompanyAiAccess::accountFor($request->user());
        abort_if($account === null, HttpResponse::HTTP_FORBIDDEN);

        return Inertia::render('socialhq/settings/Ai', CompanyAiPage::props($account));
    }
}
