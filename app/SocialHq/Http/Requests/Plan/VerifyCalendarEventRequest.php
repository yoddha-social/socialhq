<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests\Plan;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Confirming an event's date: the name and dates the approver saw, so a
 * date that changed since (an edit, an import) is never confirmed unseen.
 */
class VerifyCalendarEventRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
