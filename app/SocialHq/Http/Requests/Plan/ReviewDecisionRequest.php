<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests\Plan;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An approval or a change request on copy or a creative round: a change
 * request must say what to change, an approval may carry a note. It names
 * what the approver saw (the copy version, or the creative round), so a
 * newer one is never decided unseen.
 */
class ReviewDecisionRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $needsNote = $this->route()?->getActionMethod() === 'requestChanges';
        $aboutCopy = $this->routeIs('socialhq.plan.items.copy.*');

        return [
            'note' => [$needsNote ? 'required' : 'nullable', 'string', 'max:5000'],
            'version_id' => $aboutCopy ? ['required', 'integer'] : ['exclude'],
            'round' => $aboutCopy ? ['exclude'] : ['required', 'integer', 'min:1'],
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
