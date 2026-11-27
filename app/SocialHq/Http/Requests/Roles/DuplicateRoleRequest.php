<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests\Roles;

use App\SocialHq\Access\Role;
use App\SocialHq\Access\RoleAccess;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The owner copies a role into a new custom one (RoleChanges::duplicate()).
 */
class DuplicateRoleRequest extends FormRequest
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

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $custom = Role::query()->forAccount($this->role()->account_id)->whereNull('key')->count();

                if ($custom >= Role::MAX_CUSTOM) {
                    $validator->errors()->add('role', __('socialhq_roles.errors.too_many', ['max' => Role::MAX_CUSTOM]));
                }
            },
        ];
    }
}
