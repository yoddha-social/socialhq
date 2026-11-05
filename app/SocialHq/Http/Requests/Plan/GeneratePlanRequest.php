<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests\Plan;

use App\SocialHq\Http\Requests\Plan\Concerns\ResolvesPlanContext;
use App\SocialHq\Plan\PlanAccess;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Starts an AI draft of a month, with the team's instructions for it.
 */
class GeneratePlanRequest extends FormRequest
{
    use ResolvesPlanContext;

    public function authorize(): bool
    {
        return PlanAccess::canWork($this->user(), $this->planWorkspace());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'goals' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
