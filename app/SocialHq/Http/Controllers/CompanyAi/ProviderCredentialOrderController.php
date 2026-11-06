<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\CompanyAi;

use App\Http\Controllers\Controller;
use App\SocialHq\Ai\Company\CompanyAiManager;
use App\SocialHq\Ai\Company\ProviderCredential;
use App\SocialHq\Http\Requests\CompanyAi\MoveProviderCredentialRequest;
use Illuminate\Http\RedirectResponse;

/**
 * Move up / Move down: the order LiteLLM tries the company's providers in.
 */
class ProviderCredentialOrderController extends Controller
{
    use HandlesCompanyAiErrors;

    public function __invoke(MoveProviderCredentialRequest $request, ProviderCredential $credential, CompanyAiManager $manager): RedirectResponse
    {
        return $this->attempt('provider', function () use ($request, $credential, $manager): RedirectResponse {
            $manager->move($credential, $request->user(), (string) $request->validated('direction'));

            return back();
        });
    }
}
