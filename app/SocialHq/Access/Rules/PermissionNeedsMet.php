<?php

declare(strict_types=1);

namespace App\SocialHq\Access\Rules;

use App\SocialHq\Access\Permission;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A permission set in which every permission holds what it needs
 * (Permission::needs()): "Approve posts" without "Publish without approval"
 * is refused, never fixed up silently.
 */
final class PermissionNeedsMet implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        $permissions = array_values(array_filter($value, is_string(...)));

        foreach (Permission::unmetNeeds($permissions) as $unmet) {
            $fail(__('socialhq_roles.errors.needs', [
                'permission' => __("socialhq_roles.permissions.{$unmet['permission']}.label"),
                'needs' => __("socialhq_roles.permissions.{$unmet['needs']}.label"),
            ]));
        }
    }
}
