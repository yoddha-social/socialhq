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

    /**
     * The labels people see on the form, so errors name the right field.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'about' => __('socialhq.brand.profile.about'),
            'audience' => __('socialhq.brand.profile.audience'),
            'voice' => __('socialhq.brand.profile.voice'),
            'words_to_use' => __('socialhq.brand.profile.words_to_use'),
            'words_to_avoid' => __('socialhq.brand.profile.words_to_avoid'),
        ];
    }
}
