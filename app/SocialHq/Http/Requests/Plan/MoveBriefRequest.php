<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests\Plan;

use App\SocialHq\Production\BriefStage;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A card dropped on the production board: its new column and the order of
 * the cards in that column, the moved card included.
 */
class MoveBriefRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'stage' => ['required', Rule::enum(BriefStage::class)],
            'ordered_ids' => ['required', 'array', 'max:500'],
            'ordered_ids.*' => ['integer', 'distinct'],
        ];
    }
}
