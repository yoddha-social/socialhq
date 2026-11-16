<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class SendPasswordResetLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Emails are stored in lower case; phone keyboards capitalise the first letter.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
        ];
    }
}
