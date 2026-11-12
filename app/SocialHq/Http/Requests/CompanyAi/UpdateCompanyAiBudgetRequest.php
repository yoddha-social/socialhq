<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests\CompanyAi;

use App\SocialHq\Ai\Company\CompanyAiAccess;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyAiBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return CompanyAiAccess::canManage($this->user());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Empty: no budget (the providers' own limits still apply).
            'monthly_budget_usd' => ['nullable', 'numeric', 'min:1', 'max:100000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'monthly_budget_usd.numeric' => __('socialhq_ai.validation.budget'),
            'monthly_budget_usd.min' => __('socialhq_ai.validation.budget'),
            'monthly_budget_usd.max' => __('socialhq_ai.validation.budget'),
        ];
    }
}
