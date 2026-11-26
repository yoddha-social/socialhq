<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests\Plan;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Undo for "Confirm all shown": the dates that batch confirmed and when.
 */
class UndoConfirmShownEventsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'max:1000'],
            'ids.*' => ['integer'],
            'at' => ['required', 'date'],
        ];
    }
}
