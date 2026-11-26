<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests\Roles;

use App\SocialHq\Access\Permission;
use App\SocialHq\Access\Role;
use App\SocialHq\Access\RoleAccess;
use App\SocialHq\Access\Rules\AvailableRoleName;
use App\SocialHq\Access\Rules\PermissionNeedsMet;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The owner creates a custom role: a name, a description and its permissions.
 */
class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return RoleAccess::mayManage($this->user());
    }

    protected function failedAuthorization(): never
    {
        abort(403, __('socialhq_roles.errors.owner_only'));
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => Str::squish($this->input('name'))]);
        }

        if (is_string($this->input('description'))) {
            $this->merge(['description' => trim($this->input('description')) === '' ? null : trim($this->input('description'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.Role::NAME_MAX, new AvailableRoleName((string) $this->user()->account_id)],
            'description' => ['nullable', 'string', 'max:'.Role::DESCRIPTION_MAX],
            'permissions' => ['present', 'array', 'max:'.count(Permission::all()), new PermissionNeedsMet],
            'permissions.*' => ['string', 'distinct', Rule::in(Permission::all())],
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $custom = Role::query()->forAccount((string) $this->user()->account_id)->whereNull('key')->count();

                if ($custom >= Role::MAX_CUSTOM) {
                    $validator->errors()->add('name', __('socialhq_roles.errors.too_many', ['max' => Role::MAX_CUSTOM]));
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'permissions.*.in' => __('socialhq_roles.errors.unknown_permission'),
            'permissions.*.string' => __('socialhq_roles.errors.unknown_permission'),
        ];
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return Permission::normalize((array) $this->validated('permissions', []));
    }
}
