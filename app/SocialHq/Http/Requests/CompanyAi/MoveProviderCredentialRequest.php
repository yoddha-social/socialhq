<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests\CompanyAi;

use App\SocialHq\Ai\Company\CompanyAiAccess;
use App\SocialHq\Ai\Company\ProviderCredential;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MoveProviderCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = CompanyAiAccess::accountFor($this->user());
        $credential = $this->route('credential');

        return $account !== null && $credential instanceof ProviderCredential && $credential->account_id === $account->id;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'direction' => ['required', Rule::in(['up', 'down'])],
        ];
    }
}
