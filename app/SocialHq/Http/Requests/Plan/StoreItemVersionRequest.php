<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests\Plan;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A new version of an item's copy. The caption is capped at TryPost's post
 * ceiling; the channel's own limit is shown while writing, not enforced here,
 * since a draft may run long while it is being worked on.
 */
class StoreItemVersionRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'caption' => ['required', 'string', 'max:25000'],
            'hashtags' => ['nullable', 'string', 'max:500'],
            'script' => ['nullable', 'string', 'max:20000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
