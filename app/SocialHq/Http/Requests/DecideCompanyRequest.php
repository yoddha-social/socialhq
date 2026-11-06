<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Declining or suspending a company: the note says why, and the company's
 * owner reads it.
 */
class DecideCompanyRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'note.required' => __('socialhq_companies.platform.note_required'),
            'note.max' => __('socialhq_companies.platform.note_too_long'),
        ];
    }
}
