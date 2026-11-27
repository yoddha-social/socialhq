<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests\Plan;

use App\Enums\PostPlatform\ContentType;
use App\Models\SocialAccount;
use App\SocialHq\Http\Requests\Plan\Concerns\ResolvesPlanContext;
use App\SocialHq\Plan\MustHave;
use App\SocialHq\Plan\PlanAccess;
use App\SocialHq\Plan\PlanMonth;
use App\SocialHq\Plan\PlanOptions;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Starts an AI draft of a month, with the team's instructions for it: the
 * days to plan (inside the month), which sources the planner reads, the
 * plan's strategy (stored as the plan's goals), days off, must-have posts
 * (each on a coming day inside the days to plan, on Any or one of the brand's
 * active channels, in Any or a format that channel takes) and whether the
 * brand keeps the strategy and days off for next month. Anything left out
 * takes the brand's remembered choices.
 */
class GeneratePlanRequest extends FormRequest
{
    use ResolvesPlanContext;

    /** @var Collection<int, SocialAccount>|null */
    private ?Collection $activeChannels = null;

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
            'days_off' => ['sometimes', 'array', 'max:7'],
            'days_off.*' => ['integer', 'between:0,6', 'distinct'],
            'must_haves' => ['sometimes', 'array', 'max:'.MustHave::MAX],
            'must_haves.*' => ['array'],
            'must_haves.*.date' => ['required', 'date_format:Y-m-d'],
            'must_haves.*.channel' => ['nullable', 'string', Rule::in($this->activeChannels()->pluck('id')->all())],
            'must_haves.*.content_type' => ['nullable', 'string', Rule::enum(ContentType::class)],
            'must_haves.*.focus' => ['required', 'string', 'max:'.MustHave::MAX_FOCUS_LENGTH],
            'keep_instructions' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Each must-have's day is a coming one inside the days to plan (the
     * whole month when the days aren't given), and its format one its
     * channel takes, or for Any channel one an active channel takes.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $errors = $validator->errors();
            $mustHaves = $this->input('must_haves');

            if (! is_array($mustHaves) || $errors->hasAny(['from', 'to'])) {
                return;
            }

            $month = $this->planMonth()->toDateString();
            $from = $this->dayInput('from') ?? $month;
            $to = $this->dayInput('to') ?? PlanMonth::end($this->planMonth())->toDateString();
            $today = PlanMonth::today($this->user());

            foreach (array_values($mustHaves) as $index => $mustHave) {
                $date = (string) data_get($mustHave, 'date');

                if (! $errors->has("must_haves.{$index}.date") && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
                    if ($date < $today) {
                        $errors->add("must_haves.{$index}.date", __('socialhq_plan.generate.validation.must_have_past'));
                    } elseif ($date < $from || $date > $to) {
                        $errors->add("must_haves.{$index}.date", __('socialhq_plan.generate.validation.must_have_day'));
                    }
                }

                $type = ContentType::tryFrom((string) data_get($mustHave, 'content_type'));
                $channelId = data_get($mustHave, 'channel');
                $channels = filled($channelId) ? $this->activeChannels()->where('id', $channelId) : $this->activeChannels();

                if ($type !== null && $channels->isNotEmpty() && ! $channels->contains(fn (SocialAccount $channel): bool => PlanOptions::fitsChannel($type, $channel->platform))) {
                    $errors->add("must_haves.{$index}.content_type", __('socialhq_plan.generate.validation.must_have_format'));
                }
            }
        });
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
            'days_off.*' => __('socialhq_plan.generate.validation.days_off'),
            'days_off.max' => __('socialhq_plan.generate.validation.days_off'),
            'days_off.array' => __('socialhq_plan.generate.validation.days_off'),
            'must_haves.max' => __('socialhq_plan.generate.validation.must_haves_max', ['max' => MustHave::MAX]),
            'must_haves.array' => __('socialhq_plan.generate.validation.must_have_day'),
            'must_haves.*.array' => __('socialhq_plan.generate.validation.must_have_day'),
            'must_haves.*.date.required' => __('socialhq_plan.generate.validation.must_have_day'),
            'must_haves.*.date.date_format' => __('socialhq_plan.generate.validation.must_have_day'),
            'must_haves.*.channel' => __('socialhq_plan.generate.validation.must_have_channel'),
            'must_haves.*.content_type' => __('socialhq_plan.generate.validation.must_have_format'),
            'must_haves.*.focus.required' => __('socialhq_plan.generate.validation.must_have_focus'),
            'must_haves.*.focus.string' => __('socialhq_plan.generate.validation.must_have_focus'),
            'must_haves.*.focus.max' => __('socialhq_plan.generate.validation.must_have_focus_length', ['max' => MustHave::MAX_FOCUS_LENGTH]),
        ];
    }

    /**
     * The channels a must-have may name: connected, and not set to 0 posts a week.
     *
     * @return Collection<int, SocialAccount>
     */
    private function activeChannels(): Collection
    {
        if ($this->activeChannels !== null) {
            return $this->activeChannels;
        }

        $channels = PlanOptions::channels($this->planWorkspace());
        $cadence = PlanOptions::cadence(PlanOptions::profile($this->planWorkspace()), $channels);

        return $this->activeChannels = $channels->filter(fn (SocialAccount $channel): bool => data_get($cadence, $channel->id) !== 0)->values();
    }

    /**
     * A day field as sent, when it reads as one.
     */
    private function dayInput(string $key): ?string
    {
        $value = $this->input($key);

        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }
}
