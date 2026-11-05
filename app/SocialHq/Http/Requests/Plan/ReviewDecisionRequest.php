<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests\Plan;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An approval or a change request on copy or a creative round: a change
 * request must say what to change, an approval may carry a note.
 */
class ReviewDecisionRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $needsNote = $this->route()?->getActionMethod() === 'requestChanges';

        return [
            'note' => [$needsNote ? 'required' : 'nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'note.required' => __('socialhq_production.review.errors.note_required'),
        ];
    }
}
