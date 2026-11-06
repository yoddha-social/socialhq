<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * "Check what the AI reads": a topic, as a request to the AI would be.
 */
class SearchBrandKnowledgeRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'focus' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'focus' => __('socialhq_knowledge.check.label'),
        ];
    }
}
