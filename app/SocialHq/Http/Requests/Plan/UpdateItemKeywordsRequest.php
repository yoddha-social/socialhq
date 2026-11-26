<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests\Plan;

use App\SocialHq\Knowledge\Keywords\BrandKeywords;
use App\SocialHq\Knowledge\Keywords\KeywordPicker;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * "Keywords for this post" on an item: the keywords a person chose for its
 * copy, in their order, or null to go back to the app's pick. Only the
 * brand's own keywords are kept (KeywordPicker::chosen()).
 */
class UpdateItemKeywordsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'keywords' => ['present', 'nullable', 'array', 'list', 'max:'.KeywordPicker::maxPerPost()],
            'keywords.*' => ['required', 'string', 'max:'.BrandKeywords::MAX_LENGTH],
        ];
    }
}
