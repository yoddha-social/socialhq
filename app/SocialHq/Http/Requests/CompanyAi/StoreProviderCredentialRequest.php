<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests\CompanyAi;

use App\SocialHq\Ai\Company\CompanyAiAccess;
use App\SocialHq\Ai\Company\ProviderCatalogue;
use App\SocialHq\Ai\Company\ProviderCredential;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Adding a provider key. The key is write-only: it is never flashed back
 * as old input (SocialHqServiceProvider adds it to dontFlash) and no error
 * message repeats it. Its characters are limited to what provider keys use,
 * so it can't name a LiteLLM environment variable ("os.environ/...").
 */
class StoreProviderCredentialRequest extends FormRequest
{
    /** Provider keys are letters, digits, dots, dashes and underscores. */
    public const KEY_PATTERN = '/^[A-Za-z0-9._\-]{16,300}$/';

    /** A Cloudflare account id: 32 hexadecimal characters. */
    public const ACCOUNT_ID_PATTERN = '/^[a-f0-9]{32}$/';

    public function authorize(): bool
    {
        return CompanyAiAccess::canManage($this->user());
    }

    public function provider(): ?ProviderCatalogue
    {
        return ProviderCatalogue::find((string) $this->input('provider'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $provider = $this->provider();

        return [
            'provider' => ['required', 'string', Rule::in(ProviderCatalogue::ids())],
            'provider_key' => ['required', 'string', 'regex:'.self::KEY_PATTERN],
            'account_id' => $provider?->needsAccountId() ? ['required', 'string', 'regex:'.self::ACCOUNT_ID_PATTERN] : ['prohibited'],
            'confirmed' => $provider?->confirmation() !== null ? ['accepted'] : ['nullable'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $account = CompanyAiAccess::accountFor($this->user());

                if ($account === null || $validator->errors()->has('provider')) {
                    return;
                }

                $existing = ProviderCredential::query()->forAccount($account);

                if ((clone $existing)->where('provider', $this->input('provider'))->exists()) {
                    $validator->errors()->add('provider', __('socialhq_ai.validation.already_added'));
                } elseif ($existing->count() >= (int) config('socialhq.company_ai.max_providers', 6)) {
                    $validator->errors()->add('provider', __('socialhq_ai.validation.too_many'));
                }
            },
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
     * Non-secret fields stored with the key.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        return $this->provider()?->needsAccountId() ? ['account_id' => (string) $this->validated('account_id')] : [];
    }
}
