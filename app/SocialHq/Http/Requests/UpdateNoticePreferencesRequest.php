<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests;

use App\SocialHq\Notices\Notices;
use Illuminate\Foundation\Http\FormRequest;

class UpdateNoticePreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Each switch saves on its own, so every field is optional.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            ...collect(Notices::FIELDS)->mapWithKeys(fn (string $field): array => [$field => ['sometimes', 'boolean']])->all(),
            'live' => ['sometimes', 'boolean'],
        ];
    }
}
