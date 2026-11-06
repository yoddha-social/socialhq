<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Rename a document or turn "Use in AI writing" on or off. Its text is
 * changed by uploading the file again.
 */
class UpdateKnowledgeDocumentRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:120'],
            'use_in_ai' => ['sometimes', 'required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => __('socialhq_knowledge.documents.dialog.title'),
            'use_in_ai' => __('socialhq_knowledge.documents.dialog.use_in_ai'),
        ];
    }
}
