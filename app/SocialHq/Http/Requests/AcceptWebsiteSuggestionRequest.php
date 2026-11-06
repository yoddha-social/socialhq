<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests;

use App\SocialHq\Knowledge\KnowledgeKind;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Accepting a website suggestion, as suggested or edited: only the fields
 * of the suggestion's type are read (WebsiteReview), each within the limit
 * of the form it ends up in.
 */
class AcceptWebsiteSuggestionRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'value' => ['sometimes', 'required', 'string', 'max:2000'],
            'kind' => ['sometimes', 'required', Rule::enum(KnowledgeKind::class)],
            'title' => ['sometimes', 'required', 'string', 'max:120'],
            'body' => ['sometimes', 'required', 'string', 'max:4000'],
            'name' => ['sometimes', 'nullable', 'string', 'max:40'],
            'hex' => ['sometimes', 'required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'hex.regex' => __('socialhq_knowledge.look.errors.hex'),
            'value.required' => __('socialhq_knowledge.website.errors.empty'),
            'title.required' => __('socialhq_knowledge.website.errors.empty'),
            'body.required' => __('socialhq_knowledge.website.errors.empty'),
        ];
    }
}
