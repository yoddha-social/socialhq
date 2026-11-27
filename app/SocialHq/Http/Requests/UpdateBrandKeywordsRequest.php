<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests;

use App\SocialHq\Knowledge\BrandKnowledge;
use App\SocialHq\Knowledge\Keywords\BrandKeywords;
use App\SocialHq\Knowledge\Keywords\KeywordTagKind;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The brand's whole SEO keyword list, as the Strategy tab sends it after
 * every change (add, import, tags, main or library, remove). Each keyword
 * is cleaned first (BrandKeywords::clean()); duplicates are dropped when it
 * is saved. Each tag says its kind and what it needs: a pillar's name, a
 * product's entry, an event's key (and name), or two months.
 */
class UpdateBrandKeywordsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workspace = $this->user()?->currentWorkspace;

        return $workspace !== null && BrandKnowledge::canEdit($this->user(), $workspace);
    }

    protected function prepareForValidation(): void
    {
        $keywords = $this->input('keywords');

        if (is_array($keywords)) {
            $this->merge(['keywords' => array_map(
                fn (mixed $row): mixed => is_array($row) && is_string($row['text'] ?? null) ? [...$row, 'text' => BrandKeywords::clean($row['text'])] : $row,
                $keywords,
            )]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'keywords' => ['present', 'array', 'list', 'max:'.BrandKeywords::maxTotal()],
            'keywords.*' => ['array:text,main,tags'],
            'keywords.*.text' => ['required', 'string', 'min:'.BrandKeywords::MIN_LENGTH, 'max:'.BrandKeywords::MAX_LENGTH],
            'keywords.*.main' => ['required', 'boolean'],
            'keywords.*.tags' => ['sometimes', 'array', 'list', 'max:'.BrandKeywords::MAX_TAGS],
            'keywords.*.tags.*' => ['array:kind,value,entry_id,from,to,label'],
            'keywords.*.tags.*.kind' => ['required', Rule::enum(KeywordTagKind::class)],
            'keywords.*.tags.*.value' => ['nullable', 'required_if:keywords.*.tags.*.kind,pillar,event', 'string', 'max:160'],
            'keywords.*.tags.*.entry_id' => ['nullable', 'required_if:keywords.*.tags.*.kind,product', 'integer'],
            'keywords.*.tags.*.from' => ['nullable', 'required_if:keywords.*.tags.*.kind,months', 'integer', 'between:1,12'],
            'keywords.*.tags.*.to' => ['nullable', 'required_if:keywords.*.tags.*.kind,months', 'integer', 'between:1,12'],
            'keywords.*.tags.*.label' => ['nullable', 'string', 'max:160'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $keywords = $this->input('keywords');

                if ($validator->errors()->isNotEmpty() || ! is_array($keywords)) {
                    return;
                }

                $main = count(array_filter($keywords, fn (array $row): bool => filter_var($row['main'], FILTER_VALIDATE_BOOLEAN)));

                if ($main > BrandKeywords::maxMain()) {
                    $validator->errors()->add('keywords', __('socialhq.keywords.errors.too_many_main', ['max' => BrandKeywords::maxMain()]));
                }

                if (count($keywords) - $main > BrandKeywords::maxSupporting()) {
                    $validator->errors()->add('keywords', __('socialhq.keywords.errors.too_many_supporting', ['max' => BrandKeywords::maxSupporting()]));
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'keywords' => __('socialhq.keywords.title'),
            'keywords.*.text' => __('socialhq.keywords.attribute'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'keywords.max' => __('socialhq.keywords.errors.too_many', ['max' => BrandKeywords::maxTotal()]),
        ];
    }
}
