<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests\Roles;

use App\SocialHq\Access\Role;
use App\SocialHq\Access\RoleAccess;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The owner deletes a custom role. When people or pending invites have it,
 * `move_to` names the role they get instead, so nobody is left without one.
 */
class DestroyRoleRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $role = $this->role();

        return [
            'move_to' => [
                'nullable',
                'uuid',
                Rule::exists('shq_roles', 'id')->where('account_id', $role->account_id),
                Rule::notIn([$role->id]),
            ],
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $role = $this->role();

                if ($role->isBuiltIn()) {
                    $validator->errors()->add('role', __('socialhq_roles.errors.built_in_delete'));

                    return;
                }

                $inUse = $role->membershipCount() > 0 || $role->pendingInviteCount() > 0;

                if ($inUse && blank($this->input('move_to'))) {
                    $validator->errors()->add('move_to', __('socialhq_roles.errors.move_to_required'));
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
            'move_to.uuid' => __('socialhq_roles.errors.move_to_invalid'),
            'move_to.exists' => __('socialhq_roles.errors.move_to_invalid'),
            'move_to.not_in' => __('socialhq_roles.errors.move_to_invalid'),
        ];
    }

    public function moveTo(): ?Role
    {
        $id = $this->validated('move_to');

        return blank($id) ? null : RoleAccess::find($this->user(), (string) $id);
    }
}
