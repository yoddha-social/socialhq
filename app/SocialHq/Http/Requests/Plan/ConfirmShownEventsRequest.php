<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests\Plan;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Confirm all shown" on the events page: the page's period, country and
 * filter, so the server confirms the dates the person is looking at.
 */
class ConfirmShownEventsRequest extends FormRequest
{
    /** The filters of the events page. */
    public const FILTERS = ['all', 'country', 'international', 'brand', 'unverified', 'flagged'];

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'view' => ['required', Rule::in(['month', 'upcoming'])],
            'month' => ['required_if:view,month', 'nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'country' => ['nullable', 'required_if:filter,country', 'string', 'regex:/^[A-Za-z]{2}$/'],
            'filter' => ['required', Rule::in(self::FILTERS)],
        ];
    }
}
