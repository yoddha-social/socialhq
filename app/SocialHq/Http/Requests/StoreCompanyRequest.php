<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Asking to start a company from an account the person already has.
 */
class StoreCompanyRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => __('socialhq_companies.signup.name_required'),
            'name.max' => __('socialhq_companies.signup.name_too_long'),
        ];
    }
}
