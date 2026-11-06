<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBrandProfileRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'about' => ['nullable', 'string', 'max:2000'],
            'audience' => ['nullable', 'string', 'max:1000'],
            'voice' => ['nullable', 'string', 'max:1000'],
            'words_to_use' => ['nullable', 'string', 'max:1000'],
            'words_to_avoid' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
