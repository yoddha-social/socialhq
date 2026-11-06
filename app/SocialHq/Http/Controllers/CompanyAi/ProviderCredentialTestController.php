<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\CompanyAi;

use App\Http\Controllers\Controller;
use App\SocialHq\Ai\Company\CompanyAiAccess;
use App\SocialHq\Ai\Company\CompanyAiManager;
use App\SocialHq\Ai\Company\ProviderCredential;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Test" on a provider card: one short request with the stored key.
 */
class ProviderCredentialTestController extends Controller
{
    use HandlesCompanyAiErrors;

    public function __invoke(Request $request, ProviderCredential $credential, CompanyAiManager $manager): RedirectResponse
    {
        $account = CompanyAiAccess::accountFor($request->user());
        abort_if($account === null || $credential->account_id !== $account->id, Response::HTTP_FORBIDDEN);

        return $this->attempt('provider', function () use ($request, $credential, $manager): RedirectResponse {
            $result = $manager->test($credential, $request->user());
            $provider = __("socialhq_ai.providers.{$credential->provider}.name");

            if ($result->ok) {
                return $this->done(__('socialhq_ai.flash.test_passed', ['provider' => $provider]));
            }

            session()->flash('flash.banner', __('socialhq_ai.flash.test_failed', ['provider' => $provider, 'reason' => __("socialhq_ai.errors.{$result->errorKey}")]));
            session()->flash('flash.bannerStyle', 'danger');

            return back();
        });
    }
}
