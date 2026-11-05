<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests;

use App\SocialHq\Signup\AccessRequests;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApproveAccessRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'workspaces' => ['required', 'array', 'min:1'],
            'workspaces.*' => ['string', Rule::in(AccessRequests::workspacesManagedBy($this->user())->pluck('id')->all())],
            'is_admin' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'workspaces' => __('socialhq.signup.requests.pick_workspace'),
            // Rule-specific: a bare `workspaces.*` would also match `workspaces.required`.
            'workspaces.*.string' => __('socialhq.signup.requests.workspace_not_managed'),
            'workspaces.*.in' => __('socialhq.signup.requests.workspace_not_managed'),
            'is_admin' => __('socialhq.signup.requests.admin_invalid'),
        ];
    }
}
