<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests\CompanyAi;

use App\SocialHq\Ai\Company\CompanyAiAccess;
use App\SocialHq\Ai\Company\ProviderCatalogue;
use App\SocialHq\Ai\Company\ProviderCredential;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Replacing a provider's key with a new one (same provider). Write-only,
 * like adding one.
 */
class UpdateProviderCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = CompanyAiAccess::accountFor($this->user());
        $credential = $this->route('credential');

        return $account !== null && $credential instanceof ProviderCredential && $credential->account_id === $account->id;
    }

    public function provider(): ?ProviderCatalogue
    {
        $credential = $this->route('credential');

        return $credential instanceof ProviderCredential ? $credential->catalogue() : null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $provider = $this->provider();

        return [
            'provider_key' => ['required', 'string', 'regex:'.StoreProviderCredentialRequest::KEY_PATTERN],
            'account_id' => $provider?->needsAccountId() ? ['required', 'string', 'regex:'.StoreProviderCredentialRequest::ACCOUNT_ID_PATTERN] : ['prohibited'],
            'confirmed' => $provider?->confirmation() !== null ? ['accepted'] : ['nullable'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'provider_key.required' => __('socialhq_ai.validation.key_required'),
            'provider_key.regex' => __('socialhq_ai.validation.key_format'),
            'account_id.required' => __('socialhq_ai.validation.account_id'),
            'account_id.regex' => __('socialhq_ai.validation.account_id'),
            'confirmed.accepted' => __("socialhq_ai.validation.confirm_{$this->provider()?->confirmation()}"),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function options(): array
    {
        return $this->provider()?->needsAccountId() ? ['account_id' => (string) $this->validated('account_id')] : [];
    }
}
