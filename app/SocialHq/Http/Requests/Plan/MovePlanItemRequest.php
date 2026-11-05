<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests\Plan;

use App\SocialHq\Http\Requests\Plan\Concerns\ResolvesPlanContext;
use App\SocialHq\Plan\PlanAccess;
use App\SocialHq\Plan\PlanMonth;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Moves an item to another day of its month.
 */
class MovePlanItemRequest extends FormRequest
{
    use ResolvesPlanContext;

    public function authorize(): bool
    {
        $this->planItem();

        return PlanAccess::canWork($this->user(), $this->planWorkspace());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $month = $this->planMonth();

        return [
            'scheduled_on' => ['required', 'date_format:Y-m-d', "after_or_equal:{$month->toDateString()}", 'before_or_equal:'.PlanMonth::end($month)->toDateString()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'scheduled_on.after_or_equal' => __('socialhq_plan.validation.date_in_month'),
            'scheduled_on.before_or_equal' => __('socialhq_plan.validation.date_in_month'),
        ];
    }
}
