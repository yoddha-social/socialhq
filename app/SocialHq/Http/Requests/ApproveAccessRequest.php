<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests;

use App\SocialHq\Access\MemberRoles;
use App\SocialHq\Access\Role;
use App\SocialHq\Signup\AccessRequests;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            // Social HQ roles: one of the company's roles, or the old admin switch (Admin or Editor).
            'role_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('shq_roles', 'id')->where('account_id', $this->user()->account_id)],
            'is_admin' => ['required_without:role_id', 'boolean'],
        ];
    }

    /**
     * Nobody gives the approved person a role without holding all of its
     * permissions, in each workspace picked.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $workspaces = AccessRequests::workspacesManagedBy($this->user())->whereIn('id', (array) $this->input('workspaces'));

                foreach ($workspaces as $workspace) {
                    if (! MemberRoles::mayAssign($this->user(), $workspace, $this->role())) {
                        $validator->errors()->add($this->filled('role_id') ? 'role_id' : 'is_admin', __('socialhq_roles.errors.above_your_access'));

                        return;
                    }
                }
            },
        ];
    }

    /**
     * The role the approved person gets in every workspace picked.
     */
    public function role(): Role
    {
        $accountId = (string) $this->user()->account_id;

        if ($this->filled('role_id')) {
            return Role::query()->forAccount($accountId)->findOrFail((string) $this->input('role_id'));
        }

        return AccessRequests::roleFor($accountId, $this->boolean('is_admin'));
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
            'role_id' => __('socialhq_roles.errors.role_missing'),
            'is_admin' => __('socialhq.signup.requests.admin_invalid'),
        ];
    }
}
