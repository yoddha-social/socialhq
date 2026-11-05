<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests\Plan;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A comment in an item's review thread, about the current copy, the current
 * creative round, or the item in general.
 */
class StoreItemCommentRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
            'about' => ['nullable', Rule::in(['copy', 'creative'])],
        ];
    }
}
