<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests;

use App\SocialHq\Knowledge\BrandKnowledge;
use App\SocialHq\Knowledge\Calendars\ColumnMapping;
use App\SocialHq\Knowledge\Calendars\DateOrder;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The second step of an old calendar import: which column holds which
 * field (only the date is required), the sheet and the date order.
 */
class ConfirmCalendarImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workspace = $this->user()?->currentWorkspace;

        return $workspace !== null && BrandKnowledge::canEdit($this->user(), $workspace);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'sheet' => ['required', 'integer', 'min:0'],
            'date_order' => ['required', Rule::enum(DateOrder::class)],
            'mapping' => ['required', 'array'],
        ];

        foreach (ColumnMapping::FIELDS as $field) {
            $rules["mapping.{$field}"] = $field === ColumnMapping::DATE
                ? ['required', 'integer', 'min:0']
                : ['nullable', 'integer', 'min:0'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mapping.date.required' => __('socialhq_calendars.errors.date_column'),
            'mapping.required' => __('socialhq_calendars.errors.date_column'),
        ];
    }

    /**
     * Field => column index, every field present.
     *
     * @return array<string, int|null>
     */
    public function mapping(): array
    {
        return collect(ColumnMapping::FIELDS)
            ->mapWithKeys(fn (string $field): array => [$field => filled($this->validated("mapping.{$field}")) ? (int) $this->validated("mapping.{$field}") : null])
            ->all();
    }
}
