<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * "Keywords for this post" in the composer: the plan item the post is made
 * from, if any, else the day it goes out (today when none).
 */
class PostKeywordsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'plan_item_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'date_format:Y-m-d', 'after:2000-01-01', 'before:2038-01-19'],
        ];
    }
}
