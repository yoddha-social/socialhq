<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MarkNoticesReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Without ids, every notice of the workspace is marked read.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'ids' => ['sometimes', 'array', 'max:100'],
            'ids.*' => ['integer'],
        ];
    }
}
