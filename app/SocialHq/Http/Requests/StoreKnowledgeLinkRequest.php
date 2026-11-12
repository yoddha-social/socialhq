<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests;

use App\SocialHq\Knowledge\Sources\GoogleLink;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreKnowledgeLinkRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'url' => [
                'required',
                'string',
                'max:2000',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (GoogleLink::parse((string) $value) === null) {
                        $fail(__('socialhq_knowledge.links.errors.not_google'));
                    }
                },
            ],
            'title' => ['nullable', 'string', 'max:120'],
            'use_in_ai' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'url.required' => __('socialhq_knowledge.links.errors.required'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => __('socialhq_knowledge.documents.dialog.title'),
        ];
    }

    public function link(): GoogleLink
    {
        return GoogleLink::parse((string) $this->validated('url')) ?? abort(422);
    }
}
