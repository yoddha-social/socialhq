<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests\Plan;

use App\SocialHq\Http\Requests\Plan\Concerns\ResolvesPlanContext;
use App\SocialHq\Plan\ItemPosts;
use App\SocialHq\Plan\PlanAccess;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Deletes an item. When its post will still go out, the person chooses what happens to it: back to drafts, or kept scheduled (ItemPosts).
 */
class DestroyPlanItemRequest extends FormRequest
{
    use ResolvesPlanContext;

    public function authorize(): bool
    {
        $this->planItem();

        return PlanAccess::canWork($this->user(), $this->planWorkspace());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'posts' => ['nullable', 'string', Rule::in([ItemPosts::TO_DRAFTS, ItemPosts::KEEP])],
        ];
    }
}
