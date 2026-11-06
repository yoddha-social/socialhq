<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\CompanyAi;

use App\Http\Controllers\Controller;
use App\SocialHq\Ai\Company\CompanyAiAccess;
use App\SocialHq\Ai\Company\CompanyAiManager;
use App\SocialHq\Ai\Company\ProviderCatalogue;
use App\SocialHq\Ai\Company\ProviderCredential;
use App\SocialHq\Http\Requests\CompanyAi\StoreProviderCredentialRequest;
use App\SocialHq\Http\Requests\CompanyAi\UpdateProviderCredentialRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A company's provider keys: add (tested first), replace, remove. The key
 * goes from the request to LiteLLM and nowhere else.
 */
class ProviderCredentialController extends Controller
{
    use HandlesCompanyAiErrors;

    public function __construct(private readonly CompanyAiManager $manager) {}

    public function store(StoreProviderCredentialRequest $request): RedirectResponse
    {
        $account = CompanyAiAccess::accountFor($request->user());
        $provider = $request->provider();
        abort_if($account === null || ! $provider instanceof ProviderCatalogue, Response::HTTP_FORBIDDEN);

        return $this->attempt('provider_key', function () use ($request, $account, $provider): RedirectResponse {
            $this->manager->add($account, $request->user(), $provider, (string) $request->validated('provider_key'), $request->options());

            return $this->done(__('socialhq_ai.flash.added', ['provider' => __("socialhq_ai.providers.{$provider->id}.name")]));
        });
    }

    public function update(UpdateProviderCredentialRequest $request, ProviderCredential $credential): RedirectResponse
    {
        return $this->attempt('provider_key', function () use ($request, $credential): RedirectResponse {
            $this->manager->replace($credential, $request->user(), (string) $request->validated('provider_key'), $request->options());

            return $this->done(__('socialhq_ai.flash.replaced', ['provider' => __("socialhq_ai.providers.{$credential->provider}.name")]));
        });
    }

    public function destroy(Request $request, ProviderCredential $credential): RedirectResponse
    {
        $account = CompanyAiAccess::accountFor($request->user());
        abort_if($account === null || $credential->account_id !== $account->id, Response::HTTP_FORBIDDEN);

        return $this->attempt('provider', function () use ($request, $credential): RedirectResponse {
            $this->manager->remove($credential, $request->user());

            return $this->done(__('socialhq_ai.flash.removed', ['provider' => __("socialhq_ai.providers.{$credential->provider}.name")]));
        });
    }
}
