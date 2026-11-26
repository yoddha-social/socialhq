<?php

declare(strict_types=1);

namespace App\SocialHq\Access;

/**
 * How pages see a role.
 */
final class RoleResource
{
    /**
     * A role as the members and roles pages show it. A built-in role's
     * description is Social HQ's copy, in the reader's language; `defaults`
     * is what "Reset to default" brings back (null for Admin and custom roles).
     *
     * @return array{id: string, key: string|null, name: string, description: string|null, built_in: bool, fixed: bool, changed: bool, permissions: list<string>, defaults: list<string>|null}
     */
    public static function make(Role $role): array
    {
        $resettable = $role->key !== null && ! $role->isFixed();

        return [
            'id' => $role->id,
            'key' => $role->key?->value,
            'name' => $role->name,
            'description' => self::description($role),
            'built_in' => $role->isBuiltIn(),
            'fixed' => $role->isFixed(),
            'changed' => $role->isChangedFromDefault(),
            'permissions' => $role->grantedPermissions(),
            'defaults' => $resettable ? Permission::normalize($role->key->defaultPermissions()) : null,
        ];
    }

    public static function description(Role $role): ?string
    {
        return $role->key === null ? $role->description : __("socialhq_roles.built_in.{$role->key->value}");
    }

    /**
     * @return array{id: string, key: string|null, name: string}|null
     */
    public static function summary(?Role $role): ?array
    {
        if ($role === null) {
            return null;
        }

        return [
            'id' => $role->id,
            'key' => $role->key?->value,
            'name' => $role->name,
        ];
    }
}
