<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests\Plan;

use App\Enums\PostPlatform\ContentType;
use App\SocialHq\Http\Requests\Plan\Concerns\ResolvesPlanContext;
use App\SocialHq\Plan\CreativeKind;
use App\SocialHq\Plan\PlanAccess;
use App\SocialHq\Plan\PlanMonth;
use App\SocialHq\Plan\PlanOptions;
use App\SocialHq\Plan\SuggestedTime;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A new item in a month's plan, written by a person.
 */
class StorePlanItemRequest extends FormRequest
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
        $month = $this->planMonth();

        return [
            'scheduled_on' => ['required', 'date_format:Y-m-d', "after_or_equal:{$month->toDateString()}", 'before_or_equal:'.PlanMonth::end($month)->toDateString()],
            'social_account_id' => ['required', 'string', Rule::in($this->workspaceChannels()->pluck('id')->all())],
            'content_type' => ['required', Rule::enum(ContentType::class)],
            'creative' => ['required', Rule::enum(CreativeKind::class)],
            'pillar' => ['nullable', 'string', Rule::in($this->allowedPillars())],
            'title' => ['required', 'string', 'max:160'],
            'objective' => ['nullable', 'string', 'max:1000'],
            'angle' => ['nullable', 'string', 'max:2000'],
            'event_id' => ['nullable', 'integer', Rule::in($this->monthEventIds())],
            'owner_id' => ['nullable', 'string', Rule::in($this->ownerIds())],
            'suggested_time' => ['nullable', 'string', 'regex:'.SuggestedTime::PATTERN],
            'suggested_timezone' => ['nullable', 'string', 'timezone:all'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $channel = $this->workspaceChannels()->firstWhere('id', $this->input('social_account_id'));
            $type = ContentType::tryFrom((string) $this->input('content_type'));

            if ($channel !== null && $type !== null && ! PlanOptions::fitsChannel($type, $channel->platform)) {
                $validator->errors()->add('content_type', __('socialhq_plan.validation.content_type'));
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'scheduled_on.after_or_equal' => __('socialhq_plan.validation.date_in_month'),
            'scheduled_on.before_or_equal' => __('socialhq_plan.validation.date_in_month'),
            'social_account_id.in' => __('socialhq_plan.validation.channel'),
            'pillar.in' => __('socialhq_plan.validation.pillar'),
            'event_id.in' => __('socialhq_plan.validation.event'),
            'owner_id.in' => __('socialhq_plan.validation.owner'),
            'suggested_time.regex' => __('socialhq_plan.validation.time'),
            'suggested_timezone.timezone' => __('socialhq_plan.validation.timezone'),
        ];
    }
}
