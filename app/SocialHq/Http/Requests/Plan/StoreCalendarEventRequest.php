<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests\Plan;

use App\SocialHq\Events\EventCategory;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCalendarEventRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'name_ne' => ['nullable', 'string', 'max:160'],
            'category' => ['required', Rule::enum(EventCategory::class)],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on', "before_or_equal:{$this->latestEnd()}"],
            'bs_date' => ['nullable', 'string', 'max:60'],
            'public_holiday' => ['required', 'boolean'],
            'description' => ['nullable', 'string', 'max:1000'],
            'source_url' => ['nullable', 'url:http,https', 'max:500'],
            'brand_only' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ends_on.after_or_equal' => __('socialhq_events.errors.ends_before_start'),
            'ends_on.before_or_equal' => __('socialhq_events.errors.too_long'),
        ];
    }

    /**
     * An event spans at most 31 days.
     */
    private function latestEnd(): string
    {
        return rescue(
            fn (): string => CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->input('starts_on'))->addDays(30)->toDateString(),
            '9999-12-31',
            report: false,
        );
    }
}
