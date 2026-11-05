<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests;

use App\SocialHq\Knowledge\KnowledgeKind;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreKnowledgeEntryRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::enum(KnowledgeKind::class)],
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:4000'],
            'use_in_ai' => ['required', 'boolean'],
            'pinned' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The labels people see in the dialog, so errors name the right field.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'kind' => __('socialhq.brand.entries.dialog.kind'),
            'title' => __('socialhq.brand.entries.dialog.title'),
            'body' => __('socialhq.brand.entries.dialog.body'),
            'use_in_ai' => __('socialhq.brand.entries.dialog.use_in_ai'),
            'pinned' => __('socialhq.brand.entries.dialog.pinned'),
        ];
    }
}
