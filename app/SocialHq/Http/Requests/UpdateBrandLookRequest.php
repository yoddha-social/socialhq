<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests;

use App\SocialHq\Knowledge\Look\BrandLook;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBrandLookRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'colours' => ['present', 'array', 'max:'.BrandLook::MAX_COLOURS],
            'colours.*' => ['array:name,hex'],
            'colours.*.name' => ['nullable', 'string', 'max:40'],
            'colours.*.hex' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'heading_font' => ['nullable', 'string', 'max:80'],
            'body_font' => ['nullable', 'string', 'max:80'],
            'style_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'colours.max' => __('socialhq_knowledge.look.errors.too_many_colours', ['count' => BrandLook::MAX_COLOURS]),
            'colours.*.hex.required' => __('socialhq_knowledge.look.errors.hex'),
            'colours.*.hex.regex' => __('socialhq_knowledge.look.errors.hex'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'colours.*.name' => __('socialhq_knowledge.look.colour_name'),
            'heading_font' => __('socialhq_knowledge.look.heading_font'),
            'body_font' => __('socialhq_knowledge.look.body_font'),
            'style_notes' => __('socialhq_knowledge.look.style_notes'),
        ];
    }

    /**
     * The colours as stored: hex in lower case, names trimmed.
     *
     * @return list<array{name: string, hex: string}>
     */
    public function colours(): array
    {
        return array_values(array_map(fn (array $colour): array => [
            'name' => trim((string) data_get($colour, 'name', '')),
            'hex' => strtolower((string) data_get($colour, 'hex')),
        ], (array) $this->validated('colours', [])));
    }
}
