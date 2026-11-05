<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests\Plan;

use App\SocialHq\Http\Requests\Plan\Concerns\ResolvesPlanContext;
use App\SocialHq\Plan\PlanAccess;
use App\SocialHq\Plan\PlanOptions;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The brand's content pillars and how often each channel posts (admins).
 */
class UpdateBrandSettingsRequest extends FormRequest
{
    use ResolvesPlanContext;

    public function authorize(): bool
    {
        return PlanAccess::canManageBrand($this->user(), $this->planWorkspace());
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'pillars' => array_map(fn (mixed $pillar): mixed => is_string($pillar) ? trim($pillar) : $pillar, (array) $this->input('pillars', [])),
            'cadence' => (array) $this->input('cadence', []),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'pillars' => ['present', 'array', 'max:'.PlanOptions::MAX_PILLARS],
            'pillars.*' => ['required', 'string', 'max:'.PlanOptions::MAX_PILLAR_LENGTH, 'distinct:ignore_case'],
            'cadence' => ['present', 'array'],
            'cadence.*' => ['required', 'integer', 'min:0', 'max:'.PlanOptions::MAX_POSTS_PER_WEEK],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $known = $this->workspaceChannels()->pluck('id')->all();

            foreach (array_keys((array) $this->input('cadence', [])) as $channelId) {
                if (! in_array((string) $channelId, $known, true)) {
                    $validator->errors()->add('cadence', __('socialhq_plan.validation.channel'));

                    return;
                }
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pillars.max' => __('socialhq_plan.validation.pillars_max', ['max' => PlanOptions::MAX_PILLARS]),
            'pillars.*.required' => __('socialhq_plan.validation.pillar_blank'),
            'pillars.*.max' => __('socialhq_plan.validation.pillar_length', ['max' => PlanOptions::MAX_PILLAR_LENGTH]),
            'pillars.*.distinct' => __('socialhq_plan.validation.pillar_unique'),
            'cadence.*.integer' => __('socialhq_plan.validation.cadence', ['max' => PlanOptions::MAX_POSTS_PER_WEEK]),
            'cadence.*.min' => __('socialhq_plan.validation.cadence', ['max' => PlanOptions::MAX_POSTS_PER_WEEK]),
            'cadence.*.max' => __('socialhq_plan.validation.cadence', ['max' => PlanOptions::MAX_POSTS_PER_WEEK]),
        ];
    }
}
