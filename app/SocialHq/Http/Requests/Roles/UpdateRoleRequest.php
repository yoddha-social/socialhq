<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests\Roles;

use App\SocialHq\Access\Permission;
use App\SocialHq\Access\Role;
use App\SocialHq\Access\RoleAccess;
use App\SocialHq\Access\Rules\AvailableRoleName;
use App\SocialHq\Access\Rules\PermissionNeedsMet;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The owner changes a role. A built-in role keeps its name and description
 * (only its permissions change); Admin doesn't change at all.
 */
class UpdateRoleRequest extends FormRequest
{
    private ?Role $role = null;

    public function authorize(): bool
    {
        return RoleAccess::mayManage($this->user());
    }

    protected function failedAuthorization(): never
    {
        abort(403, __('socialhq_roles.errors.owner_only'));
    }

    public function role(): Role
    {
        return $this->role ??= RoleAccess::find($this->user(), (string) $this->route('role'));
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
        $role = $this->role();

        return [
            'name' => $role->isBuiltIn()
                ? ['prohibited']
                : ['sometimes', 'required', 'string', 'max:'.Role::NAME_MAX, new AvailableRoleName($role->account_id, $role->id)],
            'description' => $role->isBuiltIn()
                ? ['prohibited']
                : ['sometimes', 'nullable', 'string', 'max:'.Role::DESCRIPTION_MAX],
            'permissions' => $role->isFixed()
                ? ['prohibited']
                : ['sometimes', 'array', 'max:'.count(Permission::all()), new PermissionNeedsMet],
            'permissions.*' => ['string', 'distinct', Rule::in(Permission::all())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.prohibited' => __('socialhq_roles.errors.built_in_name'),
            'description.prohibited' => __('socialhq_roles.errors.built_in_name'),
            'permissions.prohibited' => __('socialhq_roles.errors.fixed'),
            'permissions.*.in' => __('socialhq_roles.errors.unknown_permission'),
            'permissions.*.string' => __('socialhq_roles.errors.unknown_permission'),
        ];
    }

    /**
     * The changes asked for, permissions normalized.
     *
     * @return array{name?: string, description?: string|null, permissions?: list<string>}
     */
    public function changes(): array
    {
        $validated = $this->validated();

        if (array_key_exists('permissions', $validated)) {
            $validated['permissions'] = Permission::normalize((array) $validated['permissions']);
        }

        return array_intersect_key($validated, array_flip(['name', 'description', 'permissions']));
    }
}
