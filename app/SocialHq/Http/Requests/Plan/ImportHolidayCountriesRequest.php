<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests\Plan;

use App\Support\Timezone;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Add a country": which countries' public holidays to import, for this
 * year and the next. Nepal's dates come from the official list instead.
 */
class ImportHolidayCountriesRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $thisYear = CarbonImmutable::now(Timezone::normalize($this->user()?->timezone))->year;

        return [
            'countries' => ['required', 'array', 'min:1', 'max:'.(int) config('socialhq.events.nager.max_countries', 5)],
            'countries.*' => ['required', 'string', 'distinct:ignore_case', 'regex:/^[A-Za-z]{2}$/', 'not_regex:/^np$/i'],
            'years' => ['required', 'array', 'min:1'],
            'years.*' => ['required', 'integer', 'distinct', Rule::in([$thisYear, $thisYear + 1])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'countries.required' => __('socialhq_events.countries.errors.pick_country'),
            'countries.min' => __('socialhq_events.countries.errors.pick_country'),
            'countries.max' => __('socialhq_events.countries.errors.too_many', ['max' => (int) config('socialhq.events.nager.max_countries', 5)]),
            'countries.*.not_regex' => __('socialhq_events.countries.errors.nepal'),
            'countries.*.regex' => __('socialhq_events.countries.errors.unknown'),
            'years.required' => __('socialhq_events.countries.errors.pick_year'),
            'years.min' => __('socialhq_events.countries.errors.pick_year'),
            'years.*.in' => __('socialhq_events.countries.errors.pick_year'),
        ];
    }

    /**
     * @return list<string>
     */
    public function countries(): array
    {
        return collect((array) $this->validated('countries'))->map(fn (string $code): string => strtoupper($code))->unique()->values()->all();
    }

    /**
     * @return list<int>
     */
    public function years(): array
    {
        return collect((array) $this->validated('years'))->map(fn (int|string $year): int => (int) $year)->unique()->sort()->values()->all();
    }
}
