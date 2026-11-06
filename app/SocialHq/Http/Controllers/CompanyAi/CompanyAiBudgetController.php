<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\CompanyAi;

use App\Http\Controllers\Controller;
use App\SocialHq\Ai\Company\CompanyAiAccess;
use App\SocialHq\Ai\Company\CompanyAiManager;
use App\SocialHq\Http\Requests\CompanyAi\UpdateCompanyAiBudgetRequest;
use Illuminate\Http\RedirectResponse;

/**
 * The company's monthly AI budget, held by its LiteLLM team.
 */
class CompanyAiBudgetController extends Controller
{
    use HandlesCompanyAiErrors;

    public function __invoke(UpdateCompanyAiBudgetRequest $request, CompanyAiManager $manager): RedirectResponse
    {
        $account = CompanyAiAccess::accountFor($request->user());
        $budget = $request->validated('monthly_budget_usd');

        return $this->attempt('monthly_budget_usd', function () use ($request, $manager, $account, $budget): RedirectResponse {
            $manager->setBudget($account, $request->user(), $budget === null ? null : round((float) $budget, 2));

            return $this->done(__('socialhq_ai.flash.budget_saved'));
        });
    }
}
