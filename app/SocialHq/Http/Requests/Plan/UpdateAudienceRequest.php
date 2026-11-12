<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests\Plan;

use App\SocialHq\Http\Requests\Plan\Concerns\ResolvesPlanContext;
use App\SocialHq\Knowledge\Audience\Audience;
use App\SocialHq\Knowledge\Audience\AudienceCountries;
use App\SocialHq\Knowledge\BrandKnowledge;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * Where the brand's audience is (workspace admins): up to 10 places, each a
 * country, maybe a city, a time zone and its share, the shares adding up
 * to 100 at most.
 */
class UpdateAudienceRequest extends FormRequest
{
    use ResolvesPlanContext;

    public function authorize(): bool
    {
        return BrandKnowledge::canEdit($this->user(), $this->planWorkspace());
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'locations' => array_map(fn (mixed $row): mixed => is_array($row) ? [
                ...$row,
                'country' => strtoupper(trim((string) data_get($row, 'country'))),
                'city' => filled(data_get($row, 'city')) ? trim((string) data_get($row, 'city')) : null,
            ] : $row, (array) $this->input('locations', [])),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'locations' => ['present', 'array', 'max:'.Audience::MAX_LOCATIONS],
            'locations.*' => ['array:country,city,timezone,share'],
            'locations.*.country' => ['required', 'string', 'size:2'],
            'locations.*.city' => ['nullable', 'string', 'max:'.Audience::MAX_CITY_LENGTH],
            'locations.*.timezone' => ['required', 'string', 'timezone:all'],
            'locations.*.share' => ['required', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $rows = (array) $this->input('locations', []);
            $places = [];

            foreach ($rows as $index => $row) {
                $country = (string) data_get($row, 'country');

                if ($country !== '' && ! AudienceCountries::exists($country)) {
                    $validator->errors()->add("locations.{$index}.country", __('socialhq_audience.validation.country'));
                }

                $place = $country.'|'.Str::lower((string) data_get($row, 'city'));

                if (isset($places[$place])) {
                    $validator->errors()->add("locations.{$index}.city", __('socialhq_audience.validation.duplicate'));
                }

                $places[$place] = true;
            }

            if (collect($rows)->sum(fn (mixed $row): int => is_numeric(data_get($row, 'share')) ? (int) data_get($row, 'share') : 0) > 100) {
                $validator->errors()->add('locations', __('socialhq_audience.validation.total'));
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'locations.max' => __('socialhq_audience.validation.max', ['max' => Audience::MAX_LOCATIONS]),
            'locations.*.country.required' => __('socialhq_audience.validation.country'),
            'locations.*.country.size' => __('socialhq_audience.validation.country'),
            'locations.*.city.max' => __('socialhq_audience.validation.city', ['max' => Audience::MAX_CITY_LENGTH]),
            'locations.*.timezone.required' => __('socialhq_audience.validation.timezone'),
            'locations.*.timezone.timezone' => __('socialhq_audience.validation.timezone'),
            'locations.*.share.required' => __('socialhq_audience.validation.share'),
            'locations.*.share.integer' => __('socialhq_audience.validation.share'),
            'locations.*.share.min' => __('socialhq_audience.validation.share'),
            'locations.*.share.max' => __('socialhq_audience.validation.share'),
        ];
    }
}
