<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests\Plan;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * "Write with AI" / "Rewrite with AI": an optional request in the team's
 * own words ("shorter", "add the price"), passed to the writer as data.
 */
class WriteItemCopyRequest extends FormRequest
{
    public const int INSTRUCTION_MAX_LENGTH = 300;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'instruction' => ['nullable', 'string', 'max:'.self::INSTRUCTION_MAX_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'instruction.max' => __('socialhq_production.item.copy.ai_instruction_too_long', ['max' => self::INSTRUCTION_MAX_LENGTH]),
        ];
    }
}
