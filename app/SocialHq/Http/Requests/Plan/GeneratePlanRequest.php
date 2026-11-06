<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests\Plan;

use App\SocialHq\Http\Requests\Plan\Concerns\ResolvesPlanContext;
use App\SocialHq\Plan\PlanAccess;
use App\SocialHq\Plan\PlanMonth;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Starts an AI draft of a month, with the team's instructions for it: the
 * days to plan (inside the month), which sources the planner reads, and
 * the focus. Anything left out takes the brand's remembered choices.
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
        $month = $this->planMonth()->toDateString();
        $end = PlanMonth::end($this->planMonth())->toDateString();

        return [
            'goals' => ['nullable', 'string', 'max:2000'],
            'from' => ['required_with:to', 'date_format:Y-m-d', "after_or_equal:{$month}", "before_or_equal:{$end}"],
            'to' => ['required_with:from', 'date_format:Y-m-d', "before_or_equal:{$end}", 'after_or_equal:from'],
            'old_calendars' => ['sometimes', 'boolean'],
            'past_posts' => ['sometimes', 'boolean'],
            'audience_events' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'from.after_or_equal' => __('socialhq_plan.validation.date_in_month'),
            'from.before_or_equal' => __('socialhq_plan.validation.date_in_month'),
            'to.after_or_equal' => __('socialhq_plan.generate.validation.range_order'),
            'to.before_or_equal' => __('socialhq_plan.validation.date_in_month'),
            'from.required_with' => __('socialhq_plan.generate.validation.range'),
            'to.required_with' => __('socialhq_plan.generate.validation.range'),
            'from.date_format' => __('socialhq_plan.generate.validation.range'),
            'to.date_format' => __('socialhq_plan.generate.validation.range'),
        ];
    }
}
