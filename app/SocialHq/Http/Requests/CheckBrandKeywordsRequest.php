<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests;

use App\SocialHq\Knowledge\BrandKnowledge;
use App\SocialHq\Knowledge\Keywords\BrandKeywords;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A file's keywords before they are imported, to show each one's verdict
 * in the preview. Nothing is saved.
 */
class CheckBrandKeywordsRequest extends FormRequest
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
        return [
            'keywords' => ['required', 'array', 'list', 'max:'.BrandKeywords::maxTotal()],
            'keywords.*' => ['required', 'string', 'max:'.BrandKeywords::MAX_LENGTH],
        ];
    }
}
